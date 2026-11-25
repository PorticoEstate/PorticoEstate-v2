<?php

/**
 * Bootstrap of the probes of the LEGACY twins (task #48299): booking_boapplication :189 / :247 / :350 and
 * registration_soreg :271 (see probe_legacy.php, mutate_legacy.php).
 *
 * WHY A SECOND BOOTSTRAP. bootstrap.php swaps in the PSR-4 classes of the tree under test with a plain
 * require_once. The legacy classes are not PSR-4: createObject('booking.boapplication') loads
 * PHPGW_SERVER_ROOT/booking/inc/class.boapplication.inc.php through include_class(), and a class that was
 * required from somewhere else first would be declared a second time. So the code under test has to come
 * through PHPGW_SERVER_ROOT itself, and this file builds that directory.
 *
 * THE OVERLAY. A per-process directory (sys_get_temp_dir()/pl_overlay_<pid>) that mirrors the served
 * tree's src/modules by SYMLINKS, except three files:
 *   booking/inc/class.boapplication.inc.php   → the one in the EXPORT under test (base, fix or a mutant)
 *   registration/inc/class.soreg.inc.php      → the one in the EXPORT under test
 *   sms/inc/class.sms.inc.php                 → a STUB (below), never the real file
 * PHPGW_SERVER_ROOT is defined as that directory before anything reads config/header.inc.php (which only
 * defines it when it is not defined yet). Base against fix is then an A/B on exactly those two files:
 * everything else is the served tree, the same on both sides. The overlay holds only symlinks and one
 * stub file; it is removed again by listing what was created (no directory is ever walked, so no symlink
 * is ever followed into the served tree).
 *
 * THE SMS STUB. boapplication::sendSmsNotifications() does createObject('sms.sms')->websend2pv(), and the
 * real class loads the gateway plugin and sends. The mail barrier (MAIL_TO_DISK, SMTP_HOST) does not
 * cover that. Here the real file is never loaded: `sms_sms` is a class that only COUNTS calls and keeps no
 * recipient. pl_assert_sms_is_stub() is run before any arm and the probe exits 9 unless createObject('sms.sms')
 * is exactly that class from the overlay and the real base class `sms_sms__` has not been declared.
 *
 * Output carries ids, counts and md5 only — never an address or a phone number.
 */

require_once __DIR__ . '/bootstrap.php';

/** Files under test, relative to src/modules/. */
const PL_FILES = [
    'booking/inc/class.boapplication.inc.php',
    'registration/inc/class.soreg.inc.php',
];
const PL_SERVED_MODULES = '/var/www/html/src/modules';
const PL_SMS_REL = 'sms/inc/class.sms.inc.php';

const PL_SMS_STUB = <<<'PHP'
<?php
// STUB of sms/inc/class.sms.inc.php for the probes of task #48299. The real file loads a gateway plugin and
// SENDS; this one counts calls and keeps nothing about the recipient or the text.
class sms_sms
{
    public static int $calls = 0;

    public function websend2pv($username, $sms_to, $message, $sms_type = 'text', $unicode = '0')
    {
        self::$calls++;
        return [[0], [], [0]];
    }
}
PHP;

/** Paths this process created in the overlay, in creation order: ['f'|'d', path]. Removed in reverse. */
$GLOBALS['pl_created'] = [];

/**
 * Build the overlay for an export and return its path. $export must contain src/modules/<PL_FILES>.
 */
function pl_build_overlay(string $export): string
{
    $overrides = [];
    foreach (PL_FILES as $rel) {
        $src = "$export/src/modules/$rel";
        if (!is_file($src)) {
            fwrite(STDERR, "ABORT: the export '$export' has no src/modules/$rel\n");
            exit(2);
        }
        $overrides[$rel] = $src;
    }

    $base = sys_get_temp_dir() . '/pl_overlay_' . getmypid();
    $stubDir = $base . '_stub';
    foreach ([$base, $stubDir] as $d) {
        if (file_exists($d) || is_link($d)) {
            fwrite(STDERR, "ABORT: $d already exists\n");
            exit(2);
        }
    }
    $mk = static function (string $dir): void {
        mkdir($dir, 0777, true);
        $GLOBALS['pl_created'][] = ['d', $dir];
    };
    $link = static function (string $target, string $path): void {
        symlink($target, $path);
        $GLOBALS['pl_created'][] = ['f', $path];
    };

    $mk($stubDir);
    $stub = "$stubDir/class.sms.inc.php";
    file_put_contents($stub, PL_SMS_STUB);
    $GLOBALS['pl_created'][] = ['f', $stub];
    $overrides[PL_SMS_REL] = $stub;

    $layer = function (string $rel) use (&$layer, $base, $overrides, $mk, $link): void {
        $dir = $rel === '' ? $base : "$base/$rel";
        $mk($dir);
        $served = PL_SERVED_MODULES . ($rel === '' ? '' : "/$rel");
        foreach (scandir($served) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $r = $rel === '' ? $entry : "$rel/$entry";
            if (isset($overrides[$r])) {
                $link($overrides[$r], "$dir/$entry");
            } elseif (array_filter(array_keys($overrides), static fn(string $k): bool => str_starts_with($k, "$r/")) !== []) {
                $layer($r);
            } else {
                $link(PL_SERVED_MODULES . "/$r", "$dir/$entry");
            }
        }
    };
    $layer('');

    register_shutdown_function(static function (): void {
        foreach (array_reverse($GLOBALS['pl_created']) as [$kind, $path]) {
            $kind === 'f' ? @unlink($path) : @rmdir($path);
        }
    });
    return $base;
}

/** Everything pr_bootstrap() does, with the code under test coming through the overlay. */
function pl_bootstrap(string $export): void
{
    $overlay = pl_build_overlay($export);
    define('PHPGW_SERVER_ROOT', $overlay);
    define('PHPGW_INCLUDE_ROOT', PHPGW_SERVER_ROOT);
    define('PHPGW_MODULES_PATH', '/src/modules');

    pr_bootstrap($export, pr_files_under_test());
    // A CLI process has no Host header and the legacy constructors read it (Preferences::standard_substitutes);
    // deprecations of the vendored validators are noise here. Warnings stay visible.
    $_SERVER['HTTP_HOST'] ??= 'pe-api.test';
    error_reporting(E_ALL & ~E_DEPRECATED);
    echo 'overlay: PHPGW_SERVER_ROOT=' . PHPGW_SERVER_ROOT . '; from the export: ' . implode(', ', array_map('basename', PL_FILES)) . '; sms/inc/class.sms.inc.php = STUB' . "\n";

    pl_assert_sms_is_stub();
    pl_seed_server_settings();
}

/**
 * Exit 9 unless createObject('sms.sms') is the counting stub from the overlay and the real gateway base
 * class is not loaded. Returns the verdict as a string for $why, '' when it is the stub.
 */
function pl_sms_verdict(object $sms): string
{
    $rc = new ReflectionClass($sms);
    $file = (string)$rc->getFileName();
    if ($rc->getName() !== 'sms_sms') {
        return 'class is ' . $rc->getName();
    }
    if (!str_ends_with($file, '/class.sms.inc.php') || !str_contains($file, 'pl_overlay_') || !str_contains($file, '_stub/')) {
        return 'class sms_sms is declared in ' . $file . ', not in the stub';
    }
    if (class_exists('sms_sms__', false)) {
        return 'the real base class sms_sms__ is loaded';
    }
    if (!$rc->hasMethod('websend2pv') || !$rc->hasProperty('calls')) {
        return 'not the counting stub';
    }
    return '';
}

function pl_assert_sms_is_stub(): void
{
    $why = pl_sms_verdict(createObject('sms.sms'));
    if ($why !== '') {
        fwrite(STDERR, "ABORT: sms.sms is not the stub ($why); an arm could send an SMS\n");
        exit(9);
    }
    echo "sms guard: createObject('sms.sms') is the counting stub from the overlay; sms_sms__ (the real gateway base class) is not loaded\n";
}

/**
 * The CLI bootstrap leaves Settings['server'] without the database's phpgwapi config; boapplication reads
 * smtp_server / hostname / enforce_ssl from it. SELECT only.
 */
function pl_seed_server_settings(): void
{
    $stmt = pr_db()->prepare("SELECT config_name, config_value FROM phpgw_config WHERE config_app = 'phpgwapi'");
    $stmt->execute();
    $server = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $server[$row['config_name']] = $row['config_value'];
    }
    \App\modules\phpgwapi\services\Settings::getInstance()->update('server', $server);
    $s = \App\modules\phpgwapi\services\Settings::getInstance()->get('server');
    if (empty($s['smtp_server'])) {
        fwrite(STDERR, "ABORT: Settings['server']['smtp_server'] is empty; :180 and the three send_admin_notification would return before reading any preference\n");
        exit(9);
    }
    echo 'server settings seeded from phpgw_config (' . count($server) . " keys; smtp_server is set)\n";
}

/** The citizen of pr_seed_citizen(), with the date/time preferences the legacy constructors read. */
function pl_seed_citizen(): void
{
    pr_seed_citizen();
    $S = \App\modules\phpgwapi\services\Settings::getInstance();
    $S->update('user', ['domain' => 'default', 'preferences' => ['common' => ['lang' => 'en', 'dateformat' => 'd/m-Y', 'timeformat' => '24', 'timezone' => 'Europe/Oslo']]]);
}

/**
 * Merge $values into one account's stored preference row (insert it when there is none). Only ever called
 * inside an arm's transaction, which is rolled back.
 */
function pl_set_pref(int $owner, string $app, array $values): void
{
    $db = pr_db();
    $s = $db->prepare('SELECT preference_json FROM phpgw_preferences WHERE preference_owner = ? AND preference_app = ?');
    $s->execute([$owner, $app]);
    $current = $s->fetchColumn();
    if ($current === false) {
        $db->prepare('INSERT INTO phpgw_preferences (preference_owner, preference_app, preference_json) VALUES (?, ?, ?)')
            ->execute([$owner, $app, json_encode($values)]);
        return;
    }
    $db->prepare('UPDATE phpgw_preferences SET preference_json = ? WHERE preference_owner = ? AND preference_app = ?')
        ->execute([json_encode(array_merge(json_decode((string)$current, true) ?: [], $values)), $owner, $app]);
}

/** The state the request is identified by, in three parts so a failure says which one moved. */
function pl_state(): array
{
    $P = \App\modules\phpgwapi\services\Preferences::getInstance();
    return [
        'settings_user_sha1' => sha1(serialize(\App\modules\phpgwapi\services\Settings::getInstance()->get('user'))),
        'singleton_sha1' => sha1(serialize([$P->account_id, $P->forced, $P->default, $P->user, $P->data])),
        'translation' => \App\modules\phpgwapi\services\Translation::getInstance()->get_userlang(),
    ];
}
