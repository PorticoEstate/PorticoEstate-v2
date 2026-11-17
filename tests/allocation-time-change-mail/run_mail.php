<?php

/**
 * Hermetic tests for the case officer's mail when the applicant asks for a new time, or shortens
 * one, on an allocation from Min side (GH #1392).
 *
 *   php tests/allocation-time-change-mail/run_mail.php
 *
 * Covers, with no database and no mail transport:
 *   - AllocationTimeChangeNotifier::loadOfficer: the officer's preferences resolved as the framework
 *     resolves them (forced > own > default), and the CITIZEN's Settings['user'] and the Preferences
 *     singleton left exactly as they were
 *   - AllocationTimeChangeMailRenderer: the real Twig template, the real .lang files in no/nn/en,
 *     the language swap, escape-once of every value (the applicant's and the organisation's names
 *     included), the applicant's comment
 *   - AllocationTimeChangeNotifier: recipient rules, the officer's language whatever the acting
 *     (citizen) session says, that the officer is looked up by case_officer_id and not by the
 *     application id, a log line for no officer / no address, and that NOTHING in it can throw
 *   - EmailService::sendCaseOfficerChangeMail: an empty booking config is logged, not a silent no-mail
 *   - AllocationTimeChangeService::mailCaseOfficer: the stored change survives a notifier that throws
 *
 * Translations are read from the real phpgw_{no,nn,en}.lang files by a stand-in for the global
 * lang(), so a key that is missing from a language file fails here instead of reaching an officer
 * as "!key". Mail is never sent and storage/emails is never touched.
 *
 * Environment (all optional): HD_ROOT = tree holding src/ (default: this repo);
 * VENDOR_AUTOLOAD = composer autoload with Twig (default: HD_ROOT/vendor/autoload.php).
 *
 * Exit code: 0 all pass, 1 any failure.
 */

date_default_timezone_set('Europe/Oslo');

$root = getenv('HD_ROOT') ?: dirname(__DIR__, 2);
require_once getenv('VENDOR_AUTOLOAD') ?: $root . '/vendor/autoload.php';

define('PHPGW_SERVER_ROOT', $root . '/src/modules');

foreach ([
    'booking/services/CateringText.php',
    'booking/services/CateringLangSwitch.php',
    'booking/services/EmailService.php',
    'booking/services/AllocationTimeChangeMailRenderer.php',
    'booking/services/TranslationLangSwitch.php',
    'bookingfrontend/services/AllocationTimeChangeNotifier.php',
    'bookingfrontend/repositories/AllocationTimeChangeRepository.php',
    'bookingfrontend/services/AllocationTimeChangeService.php',
] as $file) {
    require_once $root . '/src/modules/' . $file;
}

use App\modules\booking\services\AllocationTimeChangeMailRenderer;
use App\modules\booking\services\CateringLangSwitch;
use App\modules\booking\services\EmailService;
use App\modules\phpgwapi\services\Preferences;
use App\modules\phpgwapi\services\Settings;
use App\modules\bookingfrontend\repositories\AllocationTimeChangeRepository;
use App\modules\bookingfrontend\services\AllocationTimeChangeNotifier;
use App\modules\bookingfrontend\services\AllocationTimeChangeService;

// --- a stand-in for the global lang(), backed by the real .lang files --------------------------------
$GLOBALS['__lang_now'] = 'no';
$GLOBALS['__lang_missing'] = [];
$GLOBALS['__lang_table'] = [];
foreach (['no', 'nn', 'en'] as $l) {
    foreach (['booking', 'bookingfrontend'] as $module) {
        $path = "{$root}/src/modules/{$module}/setup/phpgw_{$l}.lang";
        foreach (file($path) as $line) {
            $f = explode("\t", rtrim($line, "\r\n"));
            if (count($f) === 4) {
                $GLOBALS['__lang_table'][$l][$f[1]][strtolower($f[0])] = $f[3];
            }
        }
    }
}
if (!function_exists('lang')) {
    function lang($key, ...$args)
    {
        $module = 'booking';
        $k = $key;
        $dot = strpos($key, '.');
        if ($dot !== false && strpos(substr($key, 0, $dot), ' ') === false) {
            $module = substr($key, 0, $dot);
            $k = substr($key, $dot + 1);
        }
        $text = $GLOBALS['__lang_table'][$GLOBALS['__lang_now']][$module][strtolower($k)] ?? null;
        if ($text === null) {
            $GLOBALS['__lang_missing'][] = "{$GLOBALS['__lang_now']}:{$module}.{$k}";
            return "!{$k}";
        }
        foreach ($args as $i => $arg) {
            $text = str_replace('%' . ($i + 1), (string)$arg, $text);
        }
        return $text;
    }
}

final class FakeLangSwitch implements CateringLangSwitch
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(string $acting)
    {
        $GLOBALS['__lang_now'] = $acting;
    }

    public function current(): string
    {
        return $GLOBALS['__lang_now'];
    }

    public function set(string $lang): void
    {
        $this->calls[] = $lang;
        $GLOBALS['__lang_now'] = $lang;
    }
}

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
$same = static function (string $name, mixed $got, mixed $want) use ($check): void {
    $check($name, $got === $want, 'got ' . var_export($got, true) . ' want ' . var_export($want, true));
};
$contains = static function (string $name, string $haystack, string $needle) use ($check): void {
    $check($name, str_contains($haystack, $needle), "missing «{$needle}» in " . substr($haystack, 0, 600));
};
$lacks = static function (string $name, string $haystack, string $needle) use ($check): void {
    $check($name, !str_contains($haystack, $needle), "unexpected «{$needle}»");
};

$logFile = tempnam(sys_get_temp_dir(), 'atc-log-');
ini_set('error_log', $logFile);
ini_set('log_errors', '1');
$logTail = static fn(): string => (string)file_get_contents($logFile);
$clearLog = static function () use ($logFile): void { file_put_contents($logFile, ''); };

$thrower = new class {
    public function render(string $template, array $vars): string
    {
        throw new \TypeError('twig');
    }
};

$payload = static fn(string $kind, array $over = []): array => array_replace_recursive([
    'kind' => $kind,
    'actor' => 'Kari Nordmann',
    'application' => [
        'id' => 84260, 'id_string' => '84260', 'name' => 'Sommerfest', 'building_name' => 'Bygg A',
        'activity_name' => 'Fest', 'organization_name' => null, 'case_officer_id' => 7,
    ],
    'occurrence' => ['resource_name' => 'Storsal', 'date' => '10.12.2026', 'old_time' => '12:00–14:00', 'new_time' => '12:30–16:00'],
    'applicant_comment' => 'Vi trenger mer tid.',
    'links' => ['case' => 'https://x.test/booking/view/applications/84260'],
], $over);

// ======================================================================================================
// 1. AllocationTimeChangeMailRenderer — real template, real .lang files, language swap
// ======================================================================================================
$switch = new FakeLangSwitch('en'); // what a citizen browsing with selected_lang=en leaves behind
$renderer = new AllocationTimeChangeMailRenderer($switch);

$GLOBALS['__lang_missing'] = [];
$mail = $renderer->render($payload('requested'), 'no');
$same('requested, officer no, acting en: subject', $mail['subject'], 'Endret av søker: ønsker nytt tidspunkt – Sommerfest, 10.12.2026');
$contains('requested: intro names the applicant and says the time is unchanged', $mail['body'], 'Kari Nordmann ber om nytt tidspunkt for én dato i søknaden. Tiden er uendret til du godtar eller avslår.');
$contains('requested: application line', $mail['body'], '<b>Søknad:</b> 84260 Sommerfest');
$contains('requested: building', $mail['body'], '<b>Bygg:</b> Bygg A');
$contains('requested: activity', $mail['body'], '<b>Aktivitet:</b> Fest');
$contains('requested: resource', $mail['body'], '<b>Ressurs:</b> Storsal');
$contains('requested: date', $mail['body'], '<b>Dato:</b> 10.12.2026');
$contains('requested: old time', $mail['body'], '<b>Tidligere tid:</b> 12:00–14:00');
$contains('requested: new time', $mail['body'], '<b>Ny tid:</b> 12:30–16:00');
$contains('requested: the applicant\'s comment, labelled', $mail['body'], '<b>Kommentar fra søker:</b>');
$contains('requested: ...and verbatim', $mail['body'], 'Vi trenger mer tid.');
$contains('requested: the backend link', $mail['body'], '<a href="https://x.test/booking/view/applications/84260">Åpne saken</a>');
$same('the language is back to the citizen\'s after the render', [$switch->current(), $switch->calls], ['en', ['no', 'en']]);

$mail = $renderer->render($payload('shortened'), 'no');
$same('shortened: subject', $mail['subject'], 'Endret av søker: tidspunkt forkortet – Sommerfest, 10.12.2026');
$contains('shortened: intro says the time is already changed', $mail['body'], 'Kari Nordmann har forkortet ett tidspunkt i søknaden. Tiden er allerede endret.');
$lacks('shortened: does not say the time is unchanged', $mail['body'], 'uendret');

$mail = $renderer->render($payload('requested'), 'nn');
$same('nn subject', $mail['subject'], 'Endra av søkjar: ønskjer nytt tidspunkt – Sommerfest, 10.12.2026');
$contains('nn intro', $mail['body'], 'Kari Nordmann bed om nytt tidspunkt for éin dato i søknaden. Tida er uendra til du godtek eller avslår.');
$contains('nn labels', $mail['body'], '<b>Tidlegare tid:</b> 12:00–14:00');
$contains('nn comment label', $mail['body'], '<b>Kommentar frå søkjar:</b>');
$mail = $renderer->render($payload('shortened'), 'nn');
$same('nn shortened subject', $mail['subject'], 'Endra av søkjar: tidspunkt forkorta – Sommerfest, 10.12.2026');

$mail = $renderer->render($payload('requested'), 'en');
$same('en subject', $mail['subject'], 'Changed by applicant: requests a new time – Sommerfest, 10.12.2026');
$contains('en intro', $mail['body'], 'Kari Nordmann requests a new time for one date in the application. The time is unchanged until you accept or reject.');
$contains('en labels', $mail['body'], '<b>Previous time:</b> 12:00–14:00');
$contains('en resource label', $mail['body'], '<b>Resource:</b> Storsal');
$contains('en comment label', $mail['body'], '<b>Comment from the applicant:</b>');
$mail = $renderer->render($payload('shortened'), 'en');
$same('en shortened subject', $mail['subject'], 'Changed by applicant: time shortened – Sommerfest, 10.12.2026');
$contains('en shortened intro', $mail['body'], 'Kari Nordmann has shortened one time in the application. The time is already changed.');

// every key the mail uses exists in every language (a missing one renders as "!key")
foreach (['no', 'nn', 'en'] as $officerLang) {
    foreach (['requested', 'shortened'] as $kind) {
        $mail = $renderer->render($payload($kind), $officerLang);
        $check("no raw lang key in {$officerLang}/{$kind}", !preg_match('/![a-z_]+/', $mail['subject'] . $mail['body']), $mail['subject']);
    }
}
$same('no lang key was looked up and missing, in any language', $GLOBALS['__lang_missing'], []);

// the optional parts
$mail = $renderer->render($payload('requested', ['applicant_comment' => '']), 'no');
$lacks('no comment: no comment block', $mail['body'], 'Kommentar fra søker');
$mail = $renderer->render($payload('requested', ['applicant_comment' => "  \n "]), 'no');
$lacks('a blank comment is no comment', $mail['body'], 'Kommentar fra søker');
$mail = $renderer->render($payload('requested', ['occurrence' => ['resource_name' => '']]), 'no');
$lacks('no resource name: no resource row', $mail['body'], 'Ressurs');
$mail = $renderer->render($payload('requested', ['application' => ['organization_name' => 'Fotballaget']]), 'no');
$contains('on behalf of an organisation', $mail['body'], '(på vegne av Fotballaget)');
$mail = $renderer->render($payload('requested', ['actor' => '']), 'no');
$contains('no actor name: «Søker»', $mail['body'], 'Søker ber om nytt tidspunkt');

// text: decode stored names, escape once, the comment is the applicant's own words
$mail = $renderer->render($payload('requested', ['application' => ['name' => 'Fest &amp;#40;A&amp;#41;'], 'occurrence' => ['resource_name' => 'Hall &amp;#40;B&amp;#41;']]), 'no');
$same('subject decodes the stored name', $mail['subject'], 'Endret av søker: ønsker nytt tidspunkt – Fest (A), 10.12.2026');
$contains('body decodes the stored name', $mail['body'], 'Fest (A)');
$contains('body decodes the stored resource', $mail['body'], 'Hall (B)');
$lacks('...no literal entity reaches the officer', $mail['body'], '&#40;');
$mail = $renderer->render($payload('requested', ['applicant_comment' => "<script>alert(1)</script> & \"q\"\nlinje to"]), 'no');
$lacks('a comment cannot inject markup', $mail['body'], '<script>');
$contains('...it is escaped', $mail['body'], '&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;q&quot;');
$lacks('...and escaped once, not twice', $mail['body'], '&amp;amp;');
$contains('a line break in the comment is kept', $mail['body'], "<br />\nlinje to");
$mail = $renderer->render($payload('requested', ['application' => ['name' => "X\r\nBcc: a@b.c"]]), 'no');
$check('the subject is one line (header injection)', !preg_match('/[\r\n]/', $mail['subject']), $mail['subject']);
// the applicant's and the organisation's names are typed by citizens: markup in either is escaped, never live
$mail = $renderer->render($payload('requested', ['actor' => '<b>x</b>', 'application' => ['organization_name' => '<b>x</b>']]), 'no');
$contains('names: markup in the applicant\'s and the organisation\'s names is escaped', $mail['body'], '&lt;b&gt;x&lt;/b&gt;');
$lacks('names: ...and no live <b>x</b> reaches the officer', $mail['body'], '<b>x</b>');
$same('names: ...escaped once for EACH of the two names (the applicant\'s and the organisation\'s)', substr_count($mail['body'], '&lt;b&gt;x&lt;/b&gt;'), 2);

// the language comes back also when the render fails
$sw1 = new FakeLangSwitch('en');
$threw = null;
try {
    (new AllocationTimeChangeMailRenderer($sw1, $thrower))->render($payload('requested'), 'no');
} catch (\Throwable $e) {
    $threw = $e;
}
$check('a failing template: the error reaches the caller (the notifier contains it)', $threw instanceof \TypeError);
$same('...and the language is back', $sw1->current(), 'en');
$sw1 = new FakeLangSwitch('en');
$threw = null;
try {
    (new AllocationTimeChangeMailRenderer($sw1))->render($payload('weekly'), 'no');
} catch (\Throwable $e) {
    $threw = $e;
}
$check('an unknown kind is refused', $threw instanceof \InvalidArgumentException);
$same('...and the language is back', $sw1->current(), 'en');

// ======================================================================================================
// 2. AllocationTimeChangeNotifier — recipient rules, the officer's language, containment
// ======================================================================================================
class TestNotifier extends AllocationTimeChangeNotifier
{
    public array $delivered = [];
    public ?\Throwable $officerThrows = null;
    public ?\Throwable $linkThrows = null;
    public ?\Throwable $deliverThrows = null;
    public array $officer = ['email' => 'officer@example.test', 'lang' => 'no'];
    /** @var list<int> every account id the notifier asked loadOfficer() for */
    public array $officerIdsSeen = [];

    protected function loadOfficer(int $accountId): array
    {
        $this->officerIdsSeen[] = $accountId;
        if ($this->officerThrows) {
            throw $this->officerThrows;
        }
        return $this->officer;
    }

    protected function buildCaseLink(int $applicationId): string
    {
        if ($this->linkThrows) {
            throw $this->linkThrows;
        }
        return "https://x.test/booking/view/applications/{$applicationId}";
    }

    protected function defer(callable $work): void
    {
        $work();   // synchronously: the test wants to see the delivery
    }

    protected function deliver(string $to, string $subject, string $body): void
    {
        if ($this->deliverThrows) {
            throw $this->deliverThrows;
        }
        $this->delivered[] = [$to, $subject, $body];
    }
}

$withOfficer = static fn(int $officerId, string $kind = 'requested'): array => $payload($kind, ['application' => ['case_officer_id' => $officerId]]);

// (1) the Translation singleton says `en` (the citizen's cookie), the officer's lang is `no`
$sw2 = new FakeLangSwitch('en');
$n = new TestNotifier(new AllocationTimeChangeMailRenderer($sw2));
$n->officer = ['email' => 'officer@example.test', 'lang' => 'no'];
$same('(1) a case officer with an address gets exactly one mail', [$n->notify($withOfficer(7)), count($n->delivered)], [true, 1]);
$same('(1) ...to that officer only', $n->delivered[0][0] ?? null, 'officer@example.test');
$same('(1) ...with a Norwegian subject although the acting session is en', $n->delivered[0][1] ?? null, 'Endret av søker: ønsker nytt tidspunkt – Sommerfest, 10.12.2026');
$contains('(1) ...and a Norwegian body', $n->delivered[0][2] ?? '', '<b>Tidligere tid:</b> 12:00–14:00');
$lacks('(1) ...with no English in it', $n->delivered[0][2] ?? '', 'Previous time');
$same('(1) ...and the citizen\'s language is back afterwards', $sw2->current(), 'en');

$n = new TestNotifier(new AllocationTimeChangeMailRenderer(new FakeLangSwitch('no')));
$n->officer = ['email' => 'officer@example.test', 'lang' => 'en'];
$n->notify($withOfficer(7, 'shortened'));
$same('(1b) the other way: officer en, acting no ⇒ English', $n->delivered[0][1] ?? null, 'Changed by applicant: time shortened – Sommerfest, 10.12.2026');
$n = new TestNotifier(new AllocationTimeChangeMailRenderer(new FakeLangSwitch('en')));
$n->officer = ['email' => 'officer@example.test', 'lang' => 'nn'];
$n->notify($withOfficer(7));
$same('(1c) officer nn, acting en ⇒ nynorsk', $n->delivered[0][1] ?? null, 'Endra av søkjar: ønskjer nytt tidspunkt – Sommerfest, 10.12.2026');

// (1d) the officer is looked up by bb_application.case_officer_id, NOT by the application's own id
// (84260 in the fixture): both are plain integers, so only an assertion on the seam can tell them apart
$n = new TestNotifier(new AllocationTimeChangeMailRenderer(new FakeLangSwitch('no')));
$n->notify($withOfficer(7));
$same('(1d) loadOfficer is asked for the application\'s case_officer_id (7), once', $n->officerIdsSeen, [7]);
$check('(1d) ...and that is not the application id (84260)', !in_array(84260, $n->officerIdsSeen, true), 'loadOfficer got ' . json_encode($n->officerIdsSeen));
$n = new TestNotifier(new AllocationTimeChangeMailRenderer(new FakeLangSwitch('no')));
$n->notify($withOfficer(31));
$same('(1d) ...for another case officer too (31), not a constant', $n->officerIdsSeen, [31]);

// (2) the officer has no language preference
$n = new TestNotifier(new AllocationTimeChangeMailRenderer(new FakeLangSwitch('en')));
$n->officer = ['email' => 'officer@example.test', 'lang' => null];
$n->notify($withOfficer(7));
$same('(2) lang unset ⇒ Norwegian, whatever the citizen cookie says (cookie en)', $n->delivered[0][1] ?? null, 'Endret av søker: ønsker nytt tidspunkt – Sommerfest, 10.12.2026');
$n->officer = ['email' => 'officer@example.test', 'lang' => 'xx'];
$n->delivered = [];
$n->notify($withOfficer(7));
$same('(2) an unsupported language ⇒ Norwegian', $n->delivered[0][1] ?? null, 'Endret av søker: ønsker nytt tidspunkt – Sommerfest, 10.12.2026');
$n->officer = ['email' => 'officer@example.test'];
$n->delivered = [];
$n->notify($withOfficer(7));
$same('(2) no lang key at all ⇒ Norwegian', $n->delivered[0][1] ?? null, 'Endret av søker: ønsker nytt tidspunkt – Sommerfest, 10.12.2026');

// (3) no case officer
$clearLog();
$n = new TestNotifier(new AllocationTimeChangeMailRenderer(new FakeLangSwitch('no')));
$same('(3) no case officer (0): no mail', [$n->notify($withOfficer(0)), count($n->delivered)], [false, 0]);
$contains('(3) ...and a log line says which application', $logTail(), 'application 84260 has no case officer');
$clearLog();
$same('(3) no case officer (key absent): no mail', [$n->notify($payload('requested', ['application' => ['case_officer_id' => null]])), count($n->delivered)], [false, 0]);
$contains('(3) ...and a log line', $logTail(), 'has no case officer');

// (4) an officer without a usable address
$clearLog();
$n->officer = ['email' => '', 'lang' => 'no'];
$same('(4) an officer with no address: no mail', [$n->notify($withOfficer(9)), count($n->delivered)], [false, 0]);
$contains('(4) ...and the log names whose', $logTail(), 'case officer 9 of application 84260 has no usable email address');
$n->officer = ['email' => 'not an address', 'lang' => 'no'];
$same('(4) an unusable address: no mail', [$n->notify($withOfficer(9)), count($n->delivered)], [false, 0]);
$n->officer = ['email' => null, 'lang' => 'no'];
$same('(4) a null address: no mail', [$n->notify($withOfficer(9)), count($n->delivered)], [false, 0]);

// (5) a thrown error in deliver never reaches the caller
$clearLog();
$n = new TestNotifier(new AllocationTimeChangeMailRenderer(new FakeLangSwitch('en')));
$n->deliverThrows = new \TypeError('smtp');
$threw = false;
$res = null;
try {
    $res = $n->notify($withOfficer(7));
} catch (\Throwable $e) {
    $threw = true;
}
$check('(5) the send throws a TypeError: notify does not throw', !$threw);
$same('(5) ...it still reports the mail as composed and handed over', $res, true);
$contains('(5) ...and the failure is logged, not lost', $logTail(), 'application 84260 failed: smtp');

// containment: nothing the notifier touches may escape it, TypeError included
foreach ([
    'loading the officer throws a TypeError' => static function (TestNotifier $t): void { $t->officerThrows = new \TypeError('officer'); },
    'building the link throws a TypeError' => static function (TestNotifier $t): void { $t->linkThrows = new \TypeError('link'); },
] as $name => $arm) {
    $t = new TestNotifier(new AllocationTimeChangeMailRenderer(new FakeLangSwitch('en')));
    $arm($t);
    $threw = false;
    $result = null;
    try {
        $result = $t->notify($withOfficer(7));
    } catch (\Throwable $e) {
        $threw = true;
    }
    $check("containment: {$name}: does not throw", !$threw);
    $same("containment: {$name}: reports no mail", [$result, count($t->delivered)], [false, 0]);
}
$sw3 = new FakeLangSwitch('en');
$t = new TestNotifier(new AllocationTimeChangeMailRenderer($sw3, $thrower));
$threw = false;
$res = null;
try {
    $res = $t->notify($withOfficer(7));
} catch (\Throwable $e) {
    $threw = true;
}
$check('containment: the render throws a TypeError: notify does not throw', !$threw);
$same('...it reports no mail and the citizen\'s language is back', [$res, $sw3->current()], [false, 'en']);

// ======================================================================================================
// 2b. loadOfficer reads the officer WITHOUT becoming the current user
// ======================================================================================================
// The legacy CreateObject('phpgwapi.preferences', $id) returns the Preferences SINGLETON and calls
// setAccountId(), which rewrites Settings['user'] for the rest of the request (ofphpgwapi.inc.php:141-148
// → Preferences.php:48-55). This stand-in records the call and reproduces that write, so code that goes
// back to it is RED on the identity itself and not only on a missing function.
$GLOBALS['__createobject_calls'] = [];
if (!function_exists('CreateObject')) {
    function CreateObject($class, $accountId = null)
    {
        $GLOBALS['__createobject_calls'][] = [$class, $accountId];
        if ($class === 'phpgwapi.preferences' && $accountId) {
            Preferences::getInstance()->account_id = $accountId;
            Settings::getInstance()->update('user', ['account_id' => $accountId, 'preferences' => ['common' => ['lang' => 'no', 'email' => 'officer@example.test']]]);
        }
        return new class {
            public function read(): array
            {
                return ['common' => ['email' => 'officer@example.test', 'lang' => 'no']];
            }
        };
    }
}

// the citizen's request: Settings['user'] = the frontend's anonymous account, session language en, and
// the Preferences singleton built for it. Both are bare instances (no database in this runner).
$seedCitizen = static function (): void {
    $settings = (new ReflectionClass(Settings::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Settings::class, 'settings'))->setValue($settings, ['user' => ['account_id' => 1022, 'preferences' => ['common' => ['lang' => 'en']]]]);
    (new ReflectionProperty(Settings::class, 'instance'))->setValue(null, $settings);
    $prefs = (new ReflectionClass(Preferences::class))->newInstanceWithoutConstructor();
    $prefs->account_id = 1022;
    (new ReflectionProperty(Preferences::class, 'instance'))->setValue(null, $prefs);
};
$identity = static fn(): array => [Settings::getInstance()->get('user'), Preferences::getInstance()->account_id];

/** The production loadOfficer(), with only the database read replaced by fixed rows. */
class RowsNotifier extends AllocationTimeChangeNotifier
{
    public array $rows = [];
    public array $delivered = [];
    public ?\Throwable $rowsThrow = null;

    public function officer(int $accountId): array
    {
        return $this->loadOfficer($accountId);
    }

    protected function readCommonPreferenceRows(int $accountId): array
    {
        if ($this->rowsThrow) {
            throw $this->rowsThrow;
        }
        return $this->rows;
    }

    protected function buildCaseLink(int $applicationId): string
    {
        return "https://x.test/booking/view/applications/{$applicationId}";
    }

    protected function defer(callable $work): void
    {
        $work();
    }

    protected function deliver(string $to, string $subject, string $body): void
    {
        $this->delivered[] = [$to, $subject, $body];
    }
}

$stored = static fn(int|string $owner, array $json): array => ['preference_owner' => $owner, 'preference_json' => json_encode($json, JSON_UNESCAPED_UNICODE)];
$resolve = static function (array $rows): array {
    $n = new RowsNotifier(new AllocationTimeChangeMailRenderer(new FakeLangSwitch('no')));
    $n->rows = $rows;
    return $n->officer(7);
};

// resolution: the framework's order, forced > own > default (Preferences.php:148-168)
$seedCitizen();
$same('prefs: no rows at all ⇒ no address, no language', $resolve([]), ['email' => null, 'lang' => null]);
$same('prefs: the account\'s own address and language', $resolve([$stored(7, ['email' => 'own@example.test', 'lang' => 'en'])]), ['email' => 'own@example.test', 'lang' => 'en']);
$same('prefs: a default fills in what the account has not set', $resolve([$stored(-2, ['lang' => 'nn']), $stored(7, ['email' => 'own@example.test'])]), ['email' => 'own@example.test', 'lang' => 'nn']);
$same('prefs: a default fills in an EMPTY own value', $resolve([$stored(-2, ['lang' => 'nn']), $stored(7, ['email' => 'own@example.test', 'lang' => ''])]), ['email' => 'own@example.test', 'lang' => 'nn']);
$same('prefs: the own value beats the default', $resolve([$stored(-2, ['lang' => 'no']), $stored(7, ['lang' => 'en'])])['lang'], 'en');
$same('prefs: a FORCED value beats the account\'s own (owner -1; as on the local database, #45917)', $resolve([$stored(-1, ['lang' => 'no']), $stored(-2, ['lang' => 'nn']), $stored(7, ['lang' => 'en'])])['lang'], 'no');
$same('prefs: ...whatever order the rows come in', $resolve([$stored(7, ['lang' => 'en']), $stored(-2, ['lang' => 'nn']), $stored(-1, ['lang' => 'no'])])['lang'], 'no');
$same('prefs: owners arrive from PDO as strings', $resolve([$stored('-1', ['lang' => 'no']), $stored('7', ['lang' => 'en'])])['lang'], 'no');
$same('prefs: a forced value only touches its own key', $resolve([$stored(-1, ['lang' => 'no']), $stored(7, ['email' => 'own@example.test', 'lang' => 'en'])])['email'], 'own@example.test');
$same('prefs: a forced EMPTY value still overwrites, as in the framework', $resolve([$stored(-1, ['lang' => '']), $stored(7, ['lang' => 'en'])])['lang'], '');
$same('prefs: stored strings are unquoted like Preferences::unquote()', $resolve([$stored(7, ['email' => "o\\'brien@example.test"])])['email'], "o'brien@example.test");
$same('prefs: a row that is not valid JSON, or not an object, is skipped', $resolve([['preference_owner' => 7, 'preference_json' => '{nope'], ['preference_owner' => 7, 'preference_json' => '"text"'], $stored(-2, ['lang' => 'nn'])]), ['email' => null, 'lang' => 'nn']);

// the citizen's identity is untouched by a whole notify(), and the officer still gets the mail
$seedCitizen();
$beforeIdentity = $identity();
$GLOBALS['__createobject_calls'] = [];
$n = new RowsNotifier(new AllocationTimeChangeMailRenderer(new FakeLangSwitch('en')));
$n->rows = [$stored(-1, ['lang' => 'no']), $stored(7, ['email' => 'officer@example.test', 'lang' => 'en'])];
$res = $n->notify($withOfficer(7));
$same('identity: the officer\'s mail is sent, in Norwegian (forced), to the officer', [$res, $n->delivered[0][0] ?? null, $n->delivered[0][1] ?? null], [true, 'officer@example.test', 'Endret av søker: ønsker nytt tidspunkt – Sommerfest, 10.12.2026']);
$same('identity: Settings[user] (account id AND preferences) is byte-equal after notify()', $identity()[0], $beforeIdentity[0]);
$same('identity: ...and so is the Preferences singleton\'s account id', $identity()[1], $beforeIdentity[1]);
$same('identity: ...the citizen is still account 1022 with session language en', [Settings::getInstance()->get('user')['account_id'], Settings::getInstance()->get('user')['preferences']['common']['lang']], [1022, 'en']);
$same('identity: ...and the legacy CreateObject(phpgwapi.preferences) was never called', $GLOBALS['__createobject_calls'], []);

// ...also when something in the chain throws
$seedCitizen();
$beforeIdentity = $identity();
$n = new RowsNotifier(new AllocationTimeChangeMailRenderer(new FakeLangSwitch('en'), $thrower));
$n->rows = [$stored(7, ['email' => 'officer@example.test', 'lang' => 'no'])];
$res = $n->notify($withOfficer(7));
$same('identity: a render that throws: no mail, identity unchanged', [$res, $identity()], [false, $beforeIdentity]);
$clearLog();
$n = new RowsNotifier(new AllocationTimeChangeMailRenderer(new FakeLangSwitch('en')));
$n->rowsThrow = new \TypeError('db down');
$threw = false;
$res = null;
try {
    $res = $n->notify($withOfficer(7));
} catch (\Throwable $e) {
    $threw = true;
}
$check('identity: the preference read throws a TypeError: notify does not throw', !$threw);
$same('identity: ...no mail, a line in the log, identity unchanged', [$res, count($n->delivered), str_contains($logTail(), 'db down'), $identity()], [false, 0, true, $beforeIdentity]);

// ======================================================================================================
// 2c. EmailService::sendCaseOfficerChangeMail — an empty booking config is logged, not silent
// ======================================================================================================
/** The real method, built without its constructor (no database); only the config read is replaced. */
class ConfigLessEmailService extends EmailService
{
    public ?array $config = null;

    protected function getBookingConfig(): ?array
    {
        return $this->config;
    }
}
$mailService = (new ReflectionClass(ConfigLessEmailService::class))->newInstanceWithoutConstructor();
foreach (['null' => null, 'an empty array' => []] as $what => $config) {
    $clearLog();
    $mailService->config = $config;
    $same("mail: a booking config that is {$what}: nothing sent", $mailService->sendCaseOfficerChangeMail('officer@example.test', 'S', '<p>B</p>'), false);
    $contains("mail: ...and the log says why ({$what})", $logTail(), 'sendCaseOfficerChangeMail: mail not sent, the booking config is empty');
    $lacks("mail: ...the recipient's address is not written to the log ({$what})", $logTail(), 'officer@example.test');
    $lacks("mail: ...it is the config line, not the catch-all failure line ({$what})", $logTail(), 'failed:');
}
// the name no longer reads like the array-taking sibling it used to differ from by one letter
$same('mail: sendCaseOfficerNotification (singular) no longer exists', method_exists(EmailService::class, 'sendCaseOfficerNotification'), false);
$same('mail: sendCaseOfficerNotifications(array) is still there, untouched', method_exists(EmailService::class, 'sendCaseOfficerNotifications'), true);
$same('mail: the notifier delivers through sendCaseOfficerChangeMail', method_exists(EmailService::class, 'sendCaseOfficerChangeMail'), true);

// ======================================================================================================
// 3. AllocationTimeChangeService::mailCaseOfficer — the stored change survives the officer's mail
// ======================================================================================================
$context = ['resource_name' => 'Storsal', 'date' => '10.12.2026', 'time' => '12:00–14:00 → 12:30–16:00', 'old_time' => '12:00–14:00', 'new_time' => '12:30–16:00'];
$applicationRow = ['id' => 84260, 'id_string' => '84260', 'name' => 'Sommerfest', 'building_name' => 'Bygg A', 'activity_name' => 'Fest', 'organization_name' => null, 'case_officer_id' => 7];

class FakeRepository extends AllocationTimeChangeRepository
{
    public ?array $application = null;
    public ?\Throwable $throws = null;

    public function findApplicationForMail(int $applicationId): ?array
    {
        if ($this->throws) {
            throw $this->throws;
        }
        return $this->application;
    }
}

class RecordingNotifier extends AllocationTimeChangeNotifier
{
    public array $payloads = [];
    public ?\Throwable $throws = null;

    public function notify(array $payload): bool
    {
        if ($this->throws) {
            throw $this->throws;
        }
        $this->payloads[] = $payload;
        return true;
    }
}

/** A service with no database: only the two collaborators mailCaseOfficer() reads. */
$makeService = static function (FakeRepository $repo, AllocationTimeChangeNotifier $notifier) use ($context): array {
    $service = (new ReflectionClass(AllocationTimeChangeService::class))->newInstanceWithoutConstructor();
    foreach (['repository' => $repo, 'officerMail' => $notifier] as $prop => $value) {
        (new ReflectionProperty(AllocationTimeChangeService::class, $prop))->setValue($service, $value);
    }
    $call = static function (string $kind, string $comment = 'Vi trenger mer tid.', ?string $author = 'Kari Nordmann') use ($service, $context): void {
        (new ReflectionMethod(AllocationTimeChangeService::class, 'mailCaseOfficer'))
            ->invoke($service, $kind, 84260, $context, $comment, $author);
    };
    return [$service, $call];
};

$newRepo = static fn(): FakeRepository => (new ReflectionClass(FakeRepository::class))->newInstanceWithoutConstructor();
$newRecorder = static fn(): RecordingNotifier => (new ReflectionClass(RecordingNotifier::class))->newInstanceWithoutConstructor();

// the officer's mail is handed the application, the occurrence and the applicant's words
$repo = $newRepo();
$repo->application = $applicationRow;
$recorder = $newRecorder();
[, $call] = $makeService($repo, $recorder);
$call(AllocationTimeChangeMailRenderer::KIND_REQUESTED);
$same('service: one notify per call', count($recorder->payloads), 1);
$same('service: the payload', $recorder->payloads[0] ?? null, [
    'kind' => 'requested',
    'actor' => 'Kari Nordmann',
    'application' => $applicationRow,
    'occurrence' => ['resource_name' => 'Storsal', 'date' => '10.12.2026', 'old_time' => '12:00–14:00', 'new_time' => '12:30–16:00'],
    'applicant_comment' => 'Vi trenger mer tid.',
]);
$call(AllocationTimeChangeMailRenderer::KIND_SHORTENED, '', null);
$same('service: a shortening says so, with no comment and no author name', [$recorder->payloads[1]['kind'] ?? null, $recorder->payloads[1]['applicant_comment'] ?? null, $recorder->payloads[1]['actor'] ?? null], ['shortened', '', '']);

// a notifier that throws a PHP Error does not fail the (already committed) change
$clearLog();
$repo = $newRepo();
$repo->application = $applicationRow;
$recorder = $newRecorder();
$recorder->throws = new \TypeError('notifier');
[, $call] = $makeService($repo, $recorder);
$threw = false;
try {
    $call(AllocationTimeChangeMailRenderer::KIND_REQUESTED);
} catch (\Throwable $e) {
    $threw = true;
}
$check('service: a notifier that throws a TypeError does not reach the caller', !$threw);
$contains('service: ...and it is logged', $logTail(), 'case officer mail failed for application 84260: notifier');

$clearLog();
$repo = $newRepo();
$repo->throws = new \TypeError('read');
$recorder = $newRecorder();
[, $call] = $makeService($repo, $recorder);
$threw = false;
try {
    $call(AllocationTimeChangeMailRenderer::KIND_REQUESTED);
} catch (\Throwable $e) {
    $threw = true;
}
$check('service: a failing application read does not reach the caller', !$threw);
$same('service: ...and no mail is attempted', count($recorder->payloads), 0);
$contains('service: ...it is logged', $logTail(), 'case officer mail failed for application 84260: read');

$clearLog();
$repo = $newRepo();
$recorder = $newRecorder();
[, $call] = $makeService($repo, $recorder);
$call(AllocationTimeChangeMailRenderer::KIND_REQUESTED);
$same('service: an application that is gone gets no mail', count($recorder->payloads), 0);
$contains('service: ...and a log line says so', $logTail(), 'application 84260: the application was not found');

// the whole chain, with the transport failing: the call returns, so the citizen's request returns its outcome
$clearLog();
$repo = $newRepo();
$repo->application = $applicationRow;
$chain = new TestNotifier(new AllocationTimeChangeMailRenderer(new FakeLangSwitch('en')));
$chain->deliverThrows = new \TypeError('smtp down');
[, $call] = $makeService($repo, $chain);
$threw = false;
try {
    $call(AllocationTimeChangeMailRenderer::KIND_SHORTENED);
} catch (\Throwable $e) {
    $threw = true;
}
$check('chain: service → notifier → a send that throws a TypeError: the call returns', !$threw);
$contains('chain: ...and the send failure is logged', $logTail(), 'application 84260 failed: smtp down');

$chain = new TestNotifier(new AllocationTimeChangeMailRenderer(new FakeLangSwitch('en')));
[, $call] = $makeService($repo, $chain);
$call(AllocationTimeChangeMailRenderer::KIND_REQUESTED);
$same('chain: with a working send the officer gets one Norwegian mail', [count($chain->delivered), $chain->delivered[0][1] ?? null], [1, 'Endret av søker: ønsker nytt tidspunkt – Sommerfest, 10.12.2026']);

// STRUCTURAL (text order, not runtime): the officer's mail is called after the commit and outside the
// transaction's try/catch, in both paths, so it can neither be rolled back with nor roll back the change.
foreach (['request' => 'KIND_REQUESTED', 'shorten' => 'KIND_SHORTENED'] as $method => $kind) {
    $m = new ReflectionMethod(AllocationTimeChangeService::class, $method);
    $source = implode('', array_slice(file($m->getFileName()), $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
    $commit = strpos($source, 'transaction_commit()');
    $rethrow = strrpos($source, 'throw $e;');
    $mail = strpos($source, "mailCaseOfficer(AllocationTimeChangeMailRenderer::{$kind}");
    $check("structure: {$method}() mails the officer after the commit and after the transaction's catch",
        $commit !== false && $rethrow !== false && $mail !== false && $commit < $rethrow && $rethrow < $mail,
        "commit={$commit} rethrow={$rethrow} mail={$mail}");
    $same("structure: {$method}() mails the officer exactly once", substr_count($source, 'mailCaseOfficer('), 1);
}

// --- result ---------------------------------------------------------------------------------------------
unlink($logFile);
if ($failures) {
    fwrite(STDERR, "Mail harness: FAIL — " . count($failures) . " of " . ($passed + count($failures)) . " checks\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  ✗ {$f}\n");
    }
    exit(1);
}
echo "Allocation time change mail harness: PASS {$passed} checks on PHP " . PHP_VERSION . "\n";
