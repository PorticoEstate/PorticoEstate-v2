<?php

/**
 * Shared bootstrap of the DB-backed probes in this directory (probe_identity.php, probe_equiv.php,
 * mutate.php). They run INSIDE the pe-api container, against the real test database, on an export of
 * the tree under test copied into the container's /tmp — never on the served tree:
 *
 *   git -C <worktree> archive <commit> | tar -x -C <dir>        # the tree under test
 *   docker cp <dir> pe-api:/tmp/t47037/<name>
 *   docker exec pe-api php /tmp/t47037/<name>/tests/preferences-reader/probe_identity.php [EXPORT_DIR]
 *
 * EXPORT_DIR (default: the tree this file is in) is where the code UNDER TEST is loaded from: the
 * four call sites and PreferencesReader. Everything else comes from the served tree's autoloader.
 * That lets the SAME probe run on a base export (which has no PreferencesReader) and on the fix.
 *
 * SAFETY — what makes these probes harmless, checked here and not assumed:
 *   - MAIL_TO_DISK=1 and SMTP_HOST=disabled.invalid must already be set; nothing here changes them.
 *   - MailDiskWriter is replaced by an in-memory recorder BEFORE Send is autoloaded, so a "sent" mail
 *     is an array entry: nothing is written to storage/emails and no SMTP connection is possible.
 *   - REDIS_HOST is pointed at an unresolvable host for this process, so a bell/broadcast event is
 *     built and dropped instead of being pushed to a connected browser for a row that is rolled back.
 *   - forkNotification must run synchronously (no pcntl, no fastcgi_finish_request), or the deferred
 *     closures would run in a child process and the identity measured here would be the parent's.
 *   - every arm runs inside one transaction that is ROLLED BACK; no probe commits.
 * Output carries ids, counts and md5 of addresses — never an address.
 */

namespace App\modules\phpgwapi\services {
    /** In-memory stand-in for MailDiskWriter: isEnabled() is true, so Send::msg can never reach SMTP. */
    final class MailDiskWriter
    {
        /** @var list<array<string, mixed>> */
        public static array $captured = [];

        public static function isEnabled(): bool
        {
            return true;
        }

        public static function getPath(): string
        {
            return '/dev/null';
        }

        public static function capture($to, $subject, $body, $from = '', $cc = '', $bcc = '', $content_type = '', $attachments = [])
        {
            self::$captured[] = compact('to', 'subject', 'body', 'from', 'cc', 'bcc', 'content_type');
            return true;
        }
    }
}

namespace {
    use App\Database\Db;
    use App\modules\phpgwapi\services\MailDiskWriter;
    use App\modules\phpgwapi\services\Preferences;
    use App\modules\phpgwapi\services\Settings;
    use App\modules\phpgwapi\services\Translation;

    /** The citizen a request runs as, the officer, and the application they share (test database). */
    const PR_CITIZEN = 1022;
    const PR_OFFICER = 7;
    const PR_APPLICATION = 85034;

    /**
     * @param list<string> $filesUnderTest paths relative to the export's src/modules/ — loaded from the
     *                                     export when they exist there
     */
    function pr_bootstrap(string $export, array $filesUnderTest): void
    {
        // Buffer our own output: once anything is echoed, PHP's session_* calls in Sessions warn «headers already sent».
        ob_start();
        if (!is_dir("$export/src")) {
            fwrite(STDERR, "ABORT: no src/ in export '$export'\n");
            exit(2);
        }
        if (getenv('MAIL_TO_DISK') !== '1' || getenv('SMTP_HOST') !== 'disabled.invalid' || (string)getenv('REDIRECT_EMAIL') !== '') {
            fwrite(STDERR, "ABORT: the mail barrier is not as expected (MAIL_TO_DISK=1, SMTP_HOST=disabled.invalid, REDIRECT_EMAIL empty)\n");
            exit(9);
        }
        if (function_exists('pcntl_fork') || function_exists('fastcgi_finish_request')) {
            fwrite(STDERR, "ABORT: forkNotification would not run synchronously here; the identity measured would be the parent's\n");
            exit(9);
        }
        putenv('REDIS_HOST=disabled.invalid');
        echo 'barrier: MAIL_TO_DISK=1 SMTP_HOST=disabled.invalid REDIRECT_EMAIL empty; mail = in-memory recorder; REDIS_HOST=disabled.invalid; forkNotification synchronous; export=' . $export . "\n";

        chdir('/var/www/html');
        require_once '/var/www/html/vendor/autoload.php';
        define('SRC_ROOT_PATH', '/var/www/html/src');
        foreach (['ACL_READ' => 1, 'ACL_ADD' => 2, 'ACL_EDIT' => 4, 'ACL_DELETE' => 8, 'ACL_PRIVATE' => 16,
                  'ACL_GROUP_MANAGERS' => 32, 'ACL_CUSTOM_1' => 64, 'ACL_CUSTOM_2' => 128, 'ACL_CUSTOM_3' => 256] as $k => $v) {
            define($k, $v);
        }
        require_once SRC_ROOT_PATH . '/helpers/CommonFunctions.php';
        require_once SRC_ROOT_PATH . '/helpers/Sanitizer.php';
        require_once SRC_ROOT_PATH . '/helpers/phpgw.php';
        require_once SRC_ROOT_PATH . '/helpers/DebugArray.php';
        $dbSettings = require SRC_ROOT_PATH . '/helpers/FilterDatabaseConfig.php';
        $container = (new \DI\ContainerBuilder())->addDefinitions(['settings' => ['db' => $dbSettings]])->build();
        (new \App\providers\DatabaseServiceProvider())->register($container);
        require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';

        $loaded = [];
        foreach ($filesUnderTest as $file) {
            if (is_file("$export/src/modules/$file")) {
                require_once "$export/src/modules/$file";
                $loaded[] = $file;
            }
        }
        echo 'loaded from the export: ' . implode(', ', array_map('basename', $loaded)) . "\n";

        Settings::getInstance()->update('flags', ['currentapp' => 'bookingfrontend']);
    }

    /** The code under test, by file name under src/modules/. PreferencesReader is absent from a base export. */
    function pr_files_under_test(): array
    {
        return [
            'phpgwapi/services/PreferencesReader.php',
            'booking/services/EmailService.php',
            'bookingfrontend/services/AllocationTimeChangeNotifier.php',
            'bookingfrontend/services/applications/CateringChangeNotifier.php',
        ];
    }

    /** The citizen's request, as the frontend builds it: Settings user = the anonymous account, language en. */
    function pr_seed_citizen(): void
    {
        $S = Settings::getInstance();
        $S->update('user', ['account_id' => PR_CITIZEN, 'preferences' => ['common' => ['lang' => 'en']]]);
        $P = Preferences::getInstance(PR_CITIZEN);
        $P->setAccountId(PR_CITIZEN);   // the singleton may already exist for someone else: pin it to the citizen
        $S->update('user', ['account_id' => PR_CITIZEN, 'preferences' => ['common' => ['lang' => 'en']]]);
        Translation::getInstance()->set_userlang('en', true);
    }

    /** EVERYTHING that identifies the request: all of Settings['user'], every public field of the singleton, the language. */
    function pr_snapshot(): string
    {
        $P = Preferences::getInstance();
        return serialize([Settings::getInstance()->get('user'), $P->account_id, $P->forced, $P->default, $P->user, $P->data, Translation::getInstance()->get_userlang()]);
    }

    function pr_describe(): string
    {
        $u = Settings::getInstance()->get('user');
        return 'Settings.user.account_id=' . var_export($u['account_id'] ?? null, true)
            . ' prefs.common.lang=' . var_export($u['preferences']['common']['lang'] ?? null, true)
            . ' singleton.account_id=' . var_export(Preferences::getInstance()->account_id, true)
            . ' Translation=' . Translation::getInstance()->get_userlang();
    }

    /** What a recorded mail looked like, without any address: counts and md5 only. */
    function pr_mail_digest(array $mails): array
    {
        return array_map(static fn(array $m): array => [
            'to' => md5(strtolower(trim((string)$m['to']))),
            'cc' => $m['cc'] === '' || $m['cc'] === null ? '' : md5((string)$m['cc']),
            'bcc' => $m['bcc'] === '' || $m['bcc'] === null ? '' : md5((string)$m['bcc']),
            'subject_md5' => md5((string)$m['subject']),
            'body_md5' => md5((string)$m['body']),
            'body_len' => strlen((string)$m['body']),
        ], $mails);
    }

    function pr_db(): Db
    {
        return Db::getInstance();
    }

    /** The fixture must be what the arms assume, or every arm is measuring something else. */
    function pr_assert_fixture(): void
    {
        $s = pr_db()->prepare('SELECT case_officer_id, status FROM bb_application WHERE id = ?');
        $s->execute([PR_APPLICATION]);
        $row = $s->fetch(\PDO::FETCH_ASSOC);
        if (!$row || (int)$row['case_officer_id'] !== PR_OFFICER) {
            fwrite(STDERR, 'ABORT: fixture drift: application ' . PR_APPLICATION . ' no longer has case officer ' . PR_OFFICER . "\n");
            exit(9);
        }
    }
}
