<?php

/**
 * Hermetic tests for PreferencesReader and the five places that read another account's preferences
 * through it (task #47037):
 *
 *   php tests/preferences-reader/run_reader.php
 *
 * No database and no mail transport. Environment (all optional): HD_ROOT = tree holding src/ (default:
 * this repo; only the four files under test are loaded from it); VENDOR_AUTOLOAD = composer autoload
 * (default: HD_ROOT/vendor/autoload.php); PHPGW_SERVER_ROOT = the modules directory holding the Twig
 * views (default: HD_ROOT/src/modules) — set it to run on a partial export such as a mutant.
 *
 *   1. PreferencesReader::resolve / resolveApp against a line-for-line port of the merge in
 *      Preferences::Readpreferences() — hand-written precedence arms, every combination of own /
 *      default / forced over {absent, '', 'x', '0', a quoted string, an array}, every row order.
 *   2. PreferencesReader::read on an in-memory sqlite behind Db::getInstance(): its REAL SQL. Which
 *      statement it prepares, what is bound (never inlined), that other accounts' rows and other apps
 *      are not read, that an empty app list reads nothing.
 *   3. IDENTITY: reading leaves Settings['user'] and the Preferences singleton byte-equal, and the
 *      legacy CreateObject('phpgwapi.preferences') is never called (a stand-in that REPRODUCES the flip
 *      records every call, so code that goes back to it is RED on the identity itself).
 *   4. The three EmailService sites (:425, :711, :785) driven through the real methods, with only the
 *      building, the roles and the config stubbed: the notify bits, the cellphone, the address and the
 *      forced > own > default order come out of the reader.
 *   5. The two notifiers' loadOfficer agree with each other and with the reader.
 *   6. SOURCE GUARD: none of the five sites calls CreateObject('phpgwapi.preferences', …) any more, and
 *      AllocationTimeChangeNotifier no longer carries a reader and resolver of its own.
 *
 * The DB-backed counterparts (real database, real request paths) are probe_identity.php and
 * probe_equiv.php; mutate.php puts the singleton back at each site and expects RED.
 *
 * Exit code: 0 all pass, 1 any failure.
 */

date_default_timezone_set('Europe/Oslo');

$root = getenv('HD_ROOT') ?: dirname(__DIR__, 2);
require_once getenv('VENDOR_AUTOLOAD') ?: $root . '/vendor/autoload.php';

define('PHPGW_SERVER_ROOT', getenv('PHPGW_SERVER_ROOT') ?: $root . '/src/modules');

foreach ([
    'phpgwapi/services/PreferencesReader.php',
    'booking/services/EmailService.php',
    'bookingfrontend/services/AllocationTimeChangeNotifier.php',
    'bookingfrontend/services/applications/CateringChangeNotifier.php',
] as $file) {
    require_once $root . '/src/modules/' . $file;
}

use App\Database\Db;
use App\modules\booking\services\EmailService;
use App\modules\bookingfrontend\services\AllocationTimeChangeNotifier;
use App\modules\bookingfrontend\services\applications\CateringChangeNotifier;
use App\modules\phpgwapi\services\Preferences;
use App\modules\phpgwapi\services\PreferencesReader;
use App\modules\phpgwapi\services\Settings;

// --- tiny assertion kit --------------------------------------------------------------------------------
$failures = [];
$passed = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$failures, &$passed): void {
    if ($ok) {
        $passed++;
        return;
    }
    $failures[] = $name . ($detail !== '' ? " — {$detail}" : '');
};
/** Key order of a preference map means nothing; lists (0..n) keep theirs. */
$canon = static function (mixed $v) use (&$canon): mixed {
    if (!is_array($v)) {
        return $v;
    }
    $v = array_map($canon, $v);
    ksort($v);
    return $v;
};
$same = static function (string $name, mixed $got, mixed $want) use ($check, $canon): void {
    $check($name, $canon($got) === $canon($want), 'got ' . var_export($got, true) . ' want ' . var_export($want, true));
};

// =======================================================================================================
// 0. fixtures: an in-memory sqlite behind Db::getInstance(), a recording PDO, the legacy-call stand-in
// =======================================================================================================
final class RecordingStatement extends PDOStatement
{
    /** @var list<array{0: string, 1: ?array}> */
    public static array $executed = [];

    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        self::$executed[] = [$this->queryString, $params];
        return parent::execute($params);
    }
}

$prefsDb = new PDO('sqlite::memory:');
$prefsDb->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RecordingStatement::class]);
$dbRef = new ReflectionClass(Db::class);
$dbInstance = $dbRef->newInstanceWithoutConstructor();
$dbRef->getProperty('db')->setValue($dbInstance, $prefsDb);
$dbRef->getProperty('connected')->setValue(null, true);
$dbRef->getProperty('instance')->setValue(null, $dbInstance);

$store = static function (array $rows) use ($prefsDb): void {
    $prefsDb->exec('DROP TABLE IF EXISTS phpgw_preferences');
    $prefsDb->exec('CREATE TABLE phpgw_preferences (preference_owner INTEGER, preference_app TEXT, preference_json TEXT)');
    $insert = $prefsDb->prepare('INSERT INTO phpgw_preferences (preference_owner, preference_app, preference_json) VALUES (?, ?, ?)');
    foreach ($rows as [$owner, $app, $json]) {
        $insert->execute([$owner, $app, is_string($json) ? $json : json_encode($json, JSON_UNESCAPED_UNICODE)]);
    }
    RecordingStatement::$executed = [];
};

/** The row shape the SQL returns. */
$row = static fn(int|string $owner, string $app, array|string $json): array => [
    'preference_owner' => $owner, 'preference_app' => $app,
    'preference_json' => is_string($json) ? $json : json_encode($json, JSON_UNESCAPED_UNICODE),
];

// the legacy CreateObject: records every call and, for 'phpgwapi.preferences' with an id, REPRODUCES the flip
// (ofphpgwapi: Preferences::getInstance()->setAccountId() → Settings['user'] rewritten)
$GLOBALS['__createobject_calls'] = [];
$GLOBALS['__roles'] = [];
$GLOBALS['__sms'] = [];
if (!function_exists('CreateObject')) {
    function CreateObject($class, $accountId = null)
    {
        $GLOBALS['__createobject_calls'][] = [$class, $accountId];
        switch ($class) {
            case 'phpgwapi.preferences':
                if ($accountId) {
                    Preferences::getInstance()->account_id = $accountId;
                    Settings::getInstance()->update('user', ['account_id' => $accountId, 'preferences' => ['common' => ['lang' => 'no']]]);
                }
                return new class {
                    public function read(): array
                    {
                        return ['common' => ['email' => 'flipped@example.test', 'lang' => 'no']];
                    }
                };
            case 'booking.sopermission_building':
                return new class {
                    public function get_roles_at_building($buildingId): array
                    {
                        return $GLOBALS['__roles'];
                    }
                };
            case 'phpgwapi.sms':
                return new class {
                    public function send($phone, $text): void
                    {
                        $GLOBALS['__sms'][] = [$phone, $text];
                    }
                };
        }
        throw new LogicException("unexpected CreateObject('{$class}')");
    }
}

$seedCitizen = static function (): void {
    $settings = (new ReflectionClass(Settings::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Settings::class, 'settings'))->setValue($settings, ['user' => ['account_id' => 1022, 'preferences' => ['common' => ['lang' => 'en']]]]);
    (new ReflectionProperty(Settings::class, 'instance'))->setValue(null, $settings);
    $prefs = (new ReflectionClass(Preferences::class))->newInstanceWithoutConstructor();
    $prefs->account_id = 1022;
    (new ReflectionProperty(Preferences::class, 'instance'))->setValue(null, $prefs);
};
$identity = static fn(): array => [Settings::getInstance()->get('user'), Preferences::getInstance()->account_id];

// =======================================================================================================
// 1. the merge, against a line-for-line port of Preferences::Readpreferences()
// =======================================================================================================
/** Preferences::Readpreferences() minus the object: unquote (Preferences.php unquote), then own → defaults → forced. */
$reference = static function (array $rows): array {
    $unquote = static function (&$arr) use (&$unquote): void {
        if (!is_array($arr)) {
            $arr = @stripslashes($arr);
            return;
        }
        foreach ($arr as $key => $value) {
            if (is_array($value)) {
                $unquote($arr[$key]);
            } else {
                $arr[$key] = @stripslashes($value);
            }
        }
    };
    $forced = $default = $user = [];
    foreach ($rows as $r) {
        $app = str_replace(' ', '', $r['preference_app']);
        $value = json_decode($r['preference_json'], true);
        $unquote($value);
        if (!is_array($value)) {
            continue;
        }
        switch ($r['preference_owner']) {
            case -1:
                $forced[$app] = $value;
                break;
            case -2:
                $default[$app] = $value;
                break;
            default:
                $user[$app] = $value;
                break;
        }
    }
    $data = $user;
    foreach ($default as $app => $values) {
        foreach ($values as $var => $value) {
            if (!isset($data[$app][$var]) || $data[$app][$var] === '') {
                $data[$app][$var] = $value;
            }
        }
    }
    foreach ($forced as $app => $values) {
        foreach ($values as $var => $value) {
            $data[$app][$var] = $value;
        }
    }
    return $data;
};

// the hand-written arms (the order of precedence, stated once, in words)
$r = $row(...);
$same('resolve: no rows ⇒ nothing', PreferencesReader::resolve([]), []);
$same('resolve: the own value', PreferencesReader::resolve([$r(7, 'common', ['lang' => 'en'])]), ['common' => ['lang' => 'en']]);
$same('resolve: a default fills what is unset', PreferencesReader::resolve([$r(-2, 'common', ['lang' => 'nn']), $r(7, 'common', ['email' => 'a@x'])]), ['common' => ['email' => 'a@x', 'lang' => 'nn']]);
$same('resolve: a default fills an EMPTY own value', PreferencesReader::resolve([$r(-2, 'common', ['lang' => 'nn']), $r(7, 'common', ['lang' => ''])]), ['common' => ['lang' => 'nn']]);
$same('resolve: a default does NOT replace an own "0" (only the empty string)', PreferencesReader::resolve([$r(-2, 'booking', ['notify_on_new' => '3']), $r(7, 'booking', ['notify_on_new' => '0'])]), ['booking' => ['notify_on_new' => '0']]);
$same('resolve: the own value beats the default', PreferencesReader::resolve([$r(-2, 'common', ['lang' => 'no']), $r(7, 'common', ['lang' => 'en'])]), ['common' => ['lang' => 'en']]);
$same('resolve: a FORCED value beats the own one (#45917: forced common.lang wins over an officer\'s own)', PreferencesReader::resolve([$r(-1, 'common', ['lang' => 'no']), $r(7, 'common', ['lang' => 'en'])]), ['common' => ['lang' => 'no']]);
$same('resolve: a forced EMPTY value still overwrites', PreferencesReader::resolve([$r(-1, 'common', ['lang' => '']), $r(7, 'common', ['lang' => 'en'])]), ['common' => ['lang' => '']]);
$same('resolve: apps are independent', PreferencesReader::resolve([$r(-1, 'common', ['lang' => 'no']), $r(7, 'booking', ['notify_on_new' => '3'])]), ['common' => ['lang' => 'no'], 'booking' => ['notify_on_new' => '3']]);
$same('resolve: an app with a space in its name is filed under the name without it (Readpreferences does the same)', PreferencesReader::resolve([$r(7, 'common ', ['lang' => 'en'])]), ['common' => ['lang' => 'en']]);
$same('resolve: owners that arrive from PDO as strings', PreferencesReader::resolve([$r('-1', 'common', ['lang' => 'no']), $r('7', 'common', ['lang' => 'en'])]), ['common' => ['lang' => 'no']]);
$same('resolve: stored strings are unquoted, nested too, and non-strings are cast like stripslashes() casts them', PreferencesReader::resolve([$r(7, 'common', ['email' => "o\\'brien@x", 'n' => ['k' => "c\\'d"], 'yes' => true, 'no' => false, 'nothing' => null, 'num' => 1.5])]), ['common' => ['email' => "o'brien@x", 'n' => ['k' => "c'd"], 'yes' => '1', 'no' => '', 'nothing' => '', 'num' => '1.5']]);
$same('resolve: a row that is not valid JSON, or not an object, is skipped', PreferencesReader::resolve([$r(7, 'common', '{nope'), $r(7, 'common', '"text"'), $r(-2, 'common', ['lang' => 'nn'])]), ['common' => ['lang' => 'nn']]);
$same('resolve: $apps keeps only those apps', PreferencesReader::resolve([$r(7, 'common', ['lang' => 'en']), $r(7, 'booking', ['notify_on_new' => '3'])], ['booking']), ['booking' => ['notify_on_new' => '3']]);
$same('resolve: $apps = [] keeps nothing', PreferencesReader::resolve([$r(7, 'common', ['lang' => 'en'])], []), []);
$same('resolveApp: rows of ONE app, no preference_app needed (the shape AllocationTimeChangeNotifier::resolveCommonPreferences took)', PreferencesReader::resolveApp([
    ['preference_owner' => -1, 'preference_json' => '{"lang":"no"}'], ['preference_owner' => -2, 'preference_json' => '{"lang":"nn","timezone":"Europe/Oslo"}'], ['preference_owner' => 7, 'preference_json' => '{"email":"a@x","lang":"en"}'],
]), ['email' => 'a@x', 'lang' => 'no', 'timezone' => 'Europe/Oslo']);
$same('resolveApp: no rows ⇒ []', PreferencesReader::resolveApp([]), []);

// every combination of own / default / forced, in one app, against the port
$values = ['absent' => null, 'empty' => '', 'x' => 'x', 'zero' => '0', 'quoted' => "a\\'b", 'array' => ['n' => "c\\'d"]];
$sweep = 0;
$sweepBad = [];
foreach ($values as $ownName => $own) {
    foreach ($values as $defName => $def) {
        foreach ($values as $forcedName => $forced) {
            $rows = [];
            foreach ([7 => $own, -2 => $def, -1 => $forced] as $owner => $v) {
                if ($v !== null) {
                    $rows[] = $r($owner, 'booking', ['k' => $v, 'other' => 'o']);
                }
            }
            $sweep++;
            if ($canon(PreferencesReader::resolve($rows)) !== $canon($reference($rows))) {
                $sweepBad[] = "own={$ownName} default={$defName} forced={$forcedName}";
            }
        }
    }
}
$check("resolve == the Readpreferences port for all {$sweep} own×default×forced combinations", $sweepBad === [], implode('; ', array_slice($sweepBad, 0, 5)));

// the row ORDER must not matter (PK (owner, app): the database may return them in any order)
$base = [$r(-1, 'common', ['lang' => 'no']), $r(-2, 'common', ['lang' => 'nn', 'tz' => 'UTC']), $r(7, 'common', ['email' => 'a@x', 'lang' => 'en']), $r(-2, 'booking', ['notify_on_new' => '0']), $r(7, 'booking', ['notify_on_new' => '3'])];
$permute = static function (array $items) use (&$permute): array {
    if (count($items) <= 1) {
        return [$items];
    }
    $out = [];
    foreach ($items as $i => $item) {
        $rest = $items;
        unset($rest[$i]);
        foreach ($permute(array_values($rest)) as $p) {
            $out[] = array_merge([$item], $p);
        }
    }
    return $out;
};
$orders = $permute($base);
$badOrders = 0;
$want = $canon($reference($base));
foreach ($orders as $o) {
    $badOrders += $canon(PreferencesReader::resolve($o)) === $want ? 0 : 1;
}
$check('resolve gives the same answer for all ' . count($orders) . ' orders of the same rows', $badOrders === 0, "{$badOrders} orders differ");

// =======================================================================================================
// 2. read(): the real SQL on sqlite
// =======================================================================================================
$store([
    [-1, 'common', ['lang' => 'no']],
    [-2, 'common', ['lang' => 'nn', 'timezone' => 'Europe/Oslo']],
    [-2, 'booking', ['notify_on_new' => '0']],
    [7, 'common', ['email' => "o\\'brien@example.test", 'lang' => 'en', 'cellphone' => '+4799999999']],
    [7, 'booking', ['notify_on_new' => '3']],
    [7, 'calendar', ['weekdaystarts' => 'Monday']],
    [5, 'common', ['email' => 'five@example.test']],
    [5, 'booking', ['notify_on_new' => '1']],
    [6, 'common ', ['email' => 'six@example.test', 'cellphone' => '+4788888888']],
    [6, 'booking', ['notify_on_new' => '2']],
    [99, 'common', ['email' => 'leak@example.test']],
]);
$seedCitizen();
$beforeIdentity = $identity();
$GLOBALS['__createobject_calls'] = [];

$all = PreferencesReader::read(7);
$same('read(7): every app, forced > own > default, unquoted', $all, [
    'common' => ['email' => "o'brien@example.test", 'lang' => 'no', 'cellphone' => '+4799999999', 'timezone' => 'Europe/Oslo'],
    'booking' => ['notify_on_new' => '3'],
    'calendar' => ['weekdaystarts' => 'Monday'],
]);
$same('read(7) equals the Readpreferences port over the rows a plain SELECT returns', $all, $reference(array_map(
    static fn(array $x): array => $x,
    $prefsDb->query("SELECT preference_owner, preference_app, preference_json FROM phpgw_preferences WHERE preference_owner IN (-1, -2, 7)")->fetchAll(PDO::FETCH_ASSOC)
)));
$same('read(7, [common, booking]): only those apps', array_keys(PreferencesReader::read(7, ['common', 'booking'])), ['common', 'booking']);
$same('read(7, [common])[common][lang]: the forced one', PreferencesReader::read(7, ['common'])['common']['lang'], 'no');
$same('read(5, [common]): the account\'s own address, the forced language, the default timezone', PreferencesReader::read(5, ['common']), ['common' => ['email' => 'five@example.test', 'lang' => 'no', 'timezone' => 'Europe/Oslo']]);
$same('read(6, [common]): a row stored as "common " (trailing space) is found under common', PreferencesReader::read(6, ['common'])['common']['email'] ?? null, 'six@example.test');
$same('read(12345, [common]): an account with no rows gets the forced and default values only', PreferencesReader::read(12345, ['common']), ['common' => ['lang' => 'no', 'timezone' => 'Europe/Oslo']]);
$check('read(7): another account\'s rows are not read (99 has an address of its own)', !str_contains(json_encode(PreferencesReader::read(7)), 'leak@example.test'));
$same('read(99, [common]): that account does get its own', PreferencesReader::read(99, ['common'])['common']['email'] ?? null, 'leak@example.test');

RecordingStatement::$executed = [];
PreferencesReader::read(7, ['common']);
[$sql, $params] = RecordingStatement::$executed[0] ?? ['', null];
$same('read(7, [common]): one statement, bound as [account, app]', [count(RecordingStatement::$executed), $params], [1, [7, 'common']]);
$check('...the owner filter is the forced, the default and the account', str_contains($sql, 'preference_owner IN (-1, -2, ?)'), $sql);
$check('...the app filter is a bound placeholder, normalised for a space, and neither the id nor the app name is inlined', substr_count($sql, '?') === 2 && str_contains($sql, "REPLACE(preference_app, ' ', '') IN (?)") && !str_contains($sql, "'common'") && !str_contains($sql, '7)'), $sql);
RecordingStatement::$executed = [];
PreferencesReader::read(7, ['booking', 'common']);
$same('read(7, [booking, common]): one placeholder per app', [RecordingStatement::$executed[0][1] ?? null, substr_count(RecordingStatement::$executed[0][0] ?? '', '?')], [[7, 'booking', 'common'], 3]);
RecordingStatement::$executed = [];
PreferencesReader::read(7);
$same('read(7): no app filter at all', [RecordingStatement::$executed[0][1] ?? null, str_contains(RecordingStatement::$executed[0][0] ?? '', 'REPLACE(preference_app')], [[7], false]);
RecordingStatement::$executed = [];
$same('read(7, []): nothing asked for, nothing returned', PreferencesReader::read(7, []), []);
$same('...and no statement was even prepared', RecordingStatement::$executed, []);
$same('read(7, [an injection string]): bound, so it is just an app that does not exist', PreferencesReader::read(7, ["x') OR 1=1 --"]), []);
$same('...and the table is intact', (int)$prefsDb->query('SELECT count(*) FROM phpgw_preferences')->fetchColumn(), 11);

// =======================================================================================================
// 3. IDENTITY
// =======================================================================================================
PreferencesReader::read(7);
PreferencesReader::read(7, ['common', 'booking']);
PreferencesReader::read(99, ['common']);
PreferencesReader::resolve([$r(7, 'common', ['lang' => 'en'])]);
$same('identity: Settings[user] (account id AND preferences) and the singleton are byte-equal after the reads', $identity(), $beforeIdentity);
$same('identity: ...the citizen is still account 1022 with session language en', [Settings::getInstance()->get('user')['account_id'], Settings::getInstance()->get('user')['preferences']['common']['lang']], [1022, 'en']);
$same('identity: ...and the legacy CreateObject was never called', $GLOBALS['__createobject_calls'], []);
// the stand-in CAN say «changed» (so a probe built on it can fail)
CreateObject('phpgwapi.preferences', 7);
$check('control: the legacy call WOULD have changed the identity (the stand-in reproduces the flip)', $identity() !== $beforeIdentity);
$seedCitizen();
$GLOBALS['__createobject_calls'] = [];

// =======================================================================================================
// 4. the three EmailService sites, through the real methods
// =======================================================================================================
/** EmailService with the building, the config and (optionally) the account list stubbed; the prefs read is real. */
class WiredEmailService extends EmailService
{
    /** @var array<int, string>|null account id => address, replaces getMailAddresses() when set */
    public ?array $accounts = null;
    public ?array $config = null;
    public bool $configAsked = false;

    public function __construct()
    {
        (new ReflectionProperty(EmailService::class, 'serverSettings'))->setValue($this, ['smtp_server' => 'smtp.example.test', 'hostname' => 'x.test', 'enforce_ssl' => false]);
    }

    protected function getBuildingInfo(int $application_id): ?array
    {
        return ['id' => 1];
    }

    protected function getBookingConfig(): ?array
    {
        $this->configAsked = true;
        return $this->config;
    }

    protected function getMailAddresses(int $building_id, int $user_id = 0): array
    {
        return $this->accounts ?? parent::getMailAddresses($building_id, $user_id);
    }

    public function addresses(int $buildingId, int $officer): array
    {
        return parent::getMailAddresses($buildingId, $officer);
    }
}

// :785 getMailAddresses — building role holders 5 and 6, the officer 7; 99 is nobody's
$GLOBALS['__roles'] = [['user_id' => 5, 'role' => 'x'], ['user_id' => 6, 'role' => 'x']];
$svc = new WiredEmailService();
$seedCitizen();
$beforeIdentity = $identity();
$addr = $svc->addresses(1, 7);
$same(':785 getMailAddresses: the role holders and the officer, by common.email (own value, unquoted; "common " row found)', $addr, [5 => 'five@example.test', 6 => 'six@example.test', 7 => "o'brien@example.test"]);
$same(':785 ...identity byte-equal, and no CreateObject(phpgwapi.preferences)', [$identity(), array_values(array_filter($GLOBALS['__createobject_calls'], static fn($c) => $c[0] === 'phpgwapi.preferences'))], [$beforeIdentity, []]);
$GLOBALS['__roles'] = [['user_id' => 12345, 'role' => 'x']];
$same(':785 a role holder with no address of their own and none in the defaults is left out', $svc->addresses(1, 0), []);
$store([[-1, 'common', ['email' => 'forced@example.test']], [5, 'common', ['email' => 'five@example.test']]]);
$GLOBALS['__roles'] = [['user_id' => 5, 'role' => 'x']];
$same(':785 a FORCED address beats the own one, as it always did', $svc->addresses(1, 0), [5 => 'forced@example.test']);

// :425 sendCaseOfficerNotifications — reaches getBookingConfig() only when somebody has notify_on_new bit 1
$notify = static function (array $accountsToPrefs, array $users) use ($store): bool {
    $store($accountsToPrefs);
    $s = new WiredEmailService();
    $s->accounts = $users;
    $s->config = null;
    $s->sendCaseOfficerNotifications(['id' => 1, 'case_officer_id' => 7, 'resources' => []]);
    return $s->configAsked;
};
$seedCitizen();
$beforeIdentity = $identity();
$GLOBALS['__createobject_calls'] = [];
$same(':425 a recipient with notify_on_new bit 1 ⇒ the mail is built', $notify([[5, 'booking', ['notify_on_new' => '1']]], [5 => 'five@example.test']), true);
$same(':425 bit 3 (1+2) has bit 1', $notify([[5, 'booking', ['notify_on_new' => '3']]], [5 => 'five@example.test']), true);
$same(':425 bit 2 only ⇒ no mail', $notify([[6, 'booking', ['notify_on_new' => '2']]], [6 => 'six@example.test']), false);
$same(':425 no booking preference at all ⇒ no mail', $notify([[6, 'common', ['email' => 'six@example.test']]], [6 => 'six@example.test']), false);
$same(':425 a DEFAULT notify_on_new fills an account that has none', $notify([[-2, 'booking', ['notify_on_new' => '1']]], [6 => 'six@example.test']), true);
$same(':425 a FORCED notify_on_new beats the account\'s own', $notify([[-1, 'booking', ['notify_on_new' => '0']], [5, 'booking', ['notify_on_new' => '3']]], [5 => 'five@example.test']), false);
$same(':425 identity byte-equal across all of them, and no CreateObject(phpgwapi.preferences)', [$identity(), $GLOBALS['__createobject_calls']], [$beforeIdentity, []]);

// :711 sendSmsNotifications — the cellphone of every recipient with notify_on_new bit 2
$sms = static function (array $prefs, array $users) use ($store): array {
    $store($prefs);
    $GLOBALS['__sms'] = [];
    $s = new WiredEmailService();
    $s->accounts = $users;
    $s->config = ['application_sms_created' => 'Ny soknad'];
    $s->sendSmsNotifications(['id' => 1, 'case_officer_id' => 7]);
    return array_column($GLOBALS['__sms'], 0);
};
$seedCitizen();
$beforeIdentity = $identity();
$GLOBALS['__createobject_calls'] = [];
$smsPrefs = [[5, 'booking', ['notify_on_new' => '1']], [5, 'common', ['cellphone' => '+4711111111']], [6, 'booking', ['notify_on_new' => '2']], [6, 'common', ['cellphone' => '+4722222222']], [7, 'booking', ['notify_on_new' => '3']], [7, 'common', ['cellphone' => '+4733333333']]];
$same(':711 the cellphones of the recipients with bit 2 (bit 1 only: no SMS; bit 3 has bit 2)', $sms($smsPrefs, [5 => 'a@x', 6 => 'b@x', 7 => 'c@x']), ['+4722222222', '+4733333333']);
$same(':711 a FORCED notify_on_new beats the own one', $sms(array_merge([[-1, 'booking', ['notify_on_new' => '0']]], $smsPrefs), [5 => 'a@x', 6 => 'b@x', 7 => 'c@x']), []);
$same(':711 identity byte-equal, and no CreateObject(phpgwapi.preferences)', [$identity(), array_values(array_filter($GLOBALS['__createobject_calls'], static fn($c) => $c[0] === 'phpgwapi.preferences'))], [$beforeIdentity, []]);

// =======================================================================================================
// 5. the notifiers
// =======================================================================================================
final class OfficerOfAllocationChange extends AllocationTimeChangeNotifier
{
    public function officer(int $id): array
    {
        return $this->loadOfficer($id);
    }
}
final class OfficerOfCatering extends CateringChangeNotifier
{
    public function officer(int $id): array
    {
        return $this->loadOfficer($id);
    }
}
$store([
    [-1, 'common', ['lang' => 'no']], [-2, 'common', ['lang' => 'nn']],
    [7, 'common', ['email' => "o\\'brien@example.test", 'lang' => 'en']], [5, 'common', ['email' => 'five@example.test']], [8, 'common', ['lang' => 'en']],
]);
$seedCitizen();
$beforeIdentity = $identity();
$GLOBALS['__createobject_calls'] = [];
$a = new OfficerOfAllocationChange();
$c = new OfficerOfCatering();
foreach ([7, 5, 8, 12345] as $id) {
    $same("loadOfficer({$id}): the time-change notifier and the catering notifier agree", $a->officer($id), $c->officer($id));
}
$same('loadOfficer(7): address unquoted, forced language wins over the own one', $a->officer(7), ['email' => "o'brien@example.test", 'lang' => 'no']);
$same('loadOfficer(8): no address ⇒ null (the notifier then logs and sends nothing)', $a->officer(8), ['email' => null, 'lang' => 'no']);
$same('loadOfficer(12345): an unknown account ⇒ no address, the forced language', $c->officer(12345), ['email' => null, 'lang' => 'no']);
$same('notifiers: identity byte-equal, and no CreateObject(phpgwapi.preferences)', [$identity(), $GLOBALS['__createobject_calls']], [$beforeIdentity, []]);

// =======================================================================================================
// 6. SOURCE GUARD
// =======================================================================================================
$code = static function (string $file): string {
    $out = '';
    foreach (token_get_all((string)file_get_contents($file)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
};
$guard = [
    'EmailService' => [(new ReflectionClass(EmailService::class))->getFileName(), ["['booking']", "['booking', 'common']", "['common']"]],
    'AllocationTimeChangeNotifier' => [(new ReflectionClass(AllocationTimeChangeNotifier::class))->getFileName(), ["['common']"]],
    'CateringChangeNotifier' => [(new ReflectionClass(CateringChangeNotifier::class))->getFileName(), ["['common']"]],
];
foreach ($guard as $name => [$file, $apps]) {
    $src = $code($file);
    $same("source: {$name} has no CreateObject('phpgwapi.preferences', …) left", preg_match_all("/CreateObject\(\s*'phpgwapi\.preferences'\s*,/i", $src), 0);
    preg_match_all('/PreferencesReader::read\(\s*[^,]+,\s*(\[[^\]]*\])\s*\)/', $src, $m);
    $same("source: {$name} reads through PreferencesReader::read with these apps, in this order", $m[1], $apps);
}
$same('source: AllocationTimeChangeNotifier carries no reader/resolver of its own any more', [
    method_exists(AllocationTimeChangeNotifier::class, 'readCommonPreferenceRows'),
    method_exists(AllocationTimeChangeNotifier::class, 'resolveCommonPreferences'),
    method_exists(AllocationTimeChangeNotifier::class, 'unquote'),
], [false, false, false]);

// =======================================================================================================
$total = $passed + count($failures);
if ($failures) {
    fwrite(STDERR, "PreferencesReader harness: FAIL " . count($failures) . " of {$total}\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  FAIL {$f}\n");
    }
    exit(1);
}
echo "PreferencesReader harness: PASS {$passed} checks on PHP " . PHP_VERSION . "\n";
exit(0);
