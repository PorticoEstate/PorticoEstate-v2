<?php

/**
 * Hermetic tests for the catering-change mail and case entry (GH #1425).
 *
 *   php tests/hospitality-deadline/run_mail.php
 *
 * Covers, with no database and no mail transport:
 *   - CateringText: decode-first, escape-once, plain-text subject (rulings 34081 R1 / 34082)
 *   - HospitalityOrderDiff: what the case entry and the FROM -> TO table are made of
 *   - CateringMailRenderer: the real Twig template, the real .lang files, the language swap
 *   - CateringChangeNotifier: recipient rules and that NOTHING in it can throw
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
    'booking/services/HospitalityDeadlineCalculator.php',
    'booking/services/CateringText.php',
    'booking/services/HospitalityOrderDiff.php',
    'booking/services/CateringLangSwitch.php',
    'booking/services/CateringMailRenderer.php',
    'bookingfrontend/services/applications/CateringChangeNotifier.php',
] as $file) {
    require_once $root . '/src/modules/' . $file;
}

use App\modules\booking\services\CateringLangSwitch;
use App\modules\booking\services\CateringMailRenderer;
use App\modules\booking\services\CateringText;
use App\modules\booking\services\HospitalityOrderDiff;
use App\modules\bookingfrontend\services\applications\CateringChangeNotifier;

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
    $check($name, str_contains($haystack, $needle), "missing «{$needle}» in " . substr($haystack, 0, 400));
};
$lacks = static function (string $name, string $haystack, string $needle) use ($check): void {
    $check($name, !str_contains($haystack, $needle), "unexpected «{$needle}»");
};

// ======================================================================================================
// 1. CateringText
// ======================================================================================================
$same('decode double-encoded paren', CateringText::decode('Hall &amp;#40;A&amp;#41;'), 'Hall (A)');
$same('decode single-encoded paren', CateringText::decode('Hall &#40;A&#41;'), 'Hall (A)');
$same('decode encoded ampersand', CateringText::decode('Foo &amp; Bar'), 'Foo & Bar');
$same('a clean name is untouched', CateringText::decode('Foo & Bar (A)'), 'Foo & Bar (A)');
$same('decode a triple-encoded ampersand (only the second pass resolves it)', CateringText::decode('Foo &amp;amp; Bar'), 'Foo & Bar');
$same('decode euro sign beyond 255', CateringText::decode('&#8364;'), '€');
$same('decode null', CateringText::decode(null), '');
$same('decode norwegian letters untouched', CateringText::decode('Kjøkken på Åsane'), 'Kjøkken på Åsane');
$same('subject decodes', CateringText::subject('Hall &amp;#40;A&amp;#41;'), 'Hall (A)');
$same('subject is never html-escaped', CateringText::subject('Foo & Bar'), 'Foo & Bar');
$same('subject strips CR/LF (header injection)', CateringText::subject("Line one\r\nBcc: x@y.z"), 'Line one Bcc: x@y.z');

// ======================================================================================================
// 2. HospitalityOrderDiff
// ======================================================================================================
$line = static fn(string $name, string $qty, string $price, ?string $comment = null, string $unit = 'stk'): array => [
    'article_name' => $name, 'unit' => $unit, 'quantity' => $qty, 'unit_price' => $price,
    'amount' => number_format((float)$qty * (float)$price, 2, '.', ''), 'comment' => $comment,
];
$order = static fn(array $lines, array $over = []): array => array_merge([
    'id' => 7, 'status' => 'pending', 'hospitality_name' => 'Kantina', 'location_name' => 'Storsal',
    'serving_time_iso' => '2026-11-10 13:00:00', 'comment' => null, 'special_requirements' => null,
    'lines' => $lines,
    'total_amount' => array_sum(array_map(static fn($l) => (float)$l['amount'], $lines)),
], $over);

$d = HospitalityOrderDiff::between($order([$line('Kaffe', '3.00', '45.00')]), $order([$line('Kaffe', '4.00', '45.00')]));
$same('qty change: from', $d['old']['line:Kaffe'] ?? null, '3 stk × 45.00 = 135.00');
$same('qty change: to', $d['new']['line:Kaffe'] ?? null, '4 stk × 45.00 = 180.00');
$same('qty change: total follows', [$d['old']['total'] ?? null, $d['new']['total'] ?? null], ['135.00', '180.00']);
$same('qty change: only those two keys', array_keys($d['new']), ['line:Kaffe', 'total']);

$d = HospitalityOrderDiff::between($order([$line('Kaffe', '3', '45')]), $order([$line('Kaffe', '3', '45'), $line('Kake', '10', '20.5')]));
$same('line added: nothing before', $d['old']['line:Kake'] ?? 'absent', 'absent');
$same('line added: after', $d['new']['line:Kake'] ?? null, '10 stk × 20.50 = 205.00');

$d = HospitalityOrderDiff::between($order([$line('Kaffe', '3', '45'), $line('Kake', '10', '20')]), $order([$line('Kaffe', '3', '45')]));
$same('line removed: before', $d['old']['line:Kake'] ?? null, '10 stk × 20.00 = 200.00');
$same('line removed: nothing after', $d['new']['line:Kake'] ?? 'absent', 'absent');

$d = HospitalityOrderDiff::between($order([$line('Kaffe', '3', '45')]), $order([$line('Kaffe', '3', '45')], ['serving_time_iso' => '2026-11-10 15:30:00']));
$same('serving time is shown in venue time (winter, UTC+1)', [$d['old']['serving_time'] ?? null, $d['new']['serving_time'] ?? null], ['10.11.2026 14:00', '10.11.2026 16:30']);
$d = HospitalityOrderDiff::between($order([]), $order([], ['serving_time_iso' => '2026-07-01 10:00:00']));
$same('serving time in summer is UTC+2', $d['new']['serving_time'] ?? null, '01.07.2026 12:00');
$d = HospitalityOrderDiff::between($order([], ['serving_time_iso' => '2026-03-28 22:30:00']), $order([], ['serving_time_iso' => '2026-03-29 22:30:00']));
$same('the night of the DST start', [$d['old']['serving_time'] ?? null, $d['new']['serving_time'] ?? null], ['28.03.2026 23:30', '30.03.2026 00:30']);

$d = HospitalityOrderDiff::between($order([]), $order([], ['location_name' => 'Peisestuen', 'comment' => 'Uten nøtter']));
$same('place and comment change', [$d['old']['location'] ?? null, $d['new']['location'] ?? null, $d['new']['comment'] ?? null], ['Storsal', 'Peisestuen', 'Uten nøtter']);
$d = HospitalityOrderDiff::between($order([], ['comment' => 'Uten nøtter']), $order([], ['comment' => '']));
$same('comment cleared: before kept, nothing after', [$d['old']['comment'] ?? null, $d['new']['comment'] ?? 'absent'], ['Uten nøtter', 'absent']);

$same('no-op is empty', HospitalityOrderDiff::isEmpty(HospitalityOrderDiff::between($order([$line('Kaffe', '3', '45')]), $order([$line('Kaffe', '3', '45')]))), true);
$same('no-op with equal numbers written differently is still empty',
    HospitalityOrderDiff::isEmpty(HospitalityOrderDiff::between($order([$line('Kaffe', '3.00', '45.00')]), $order([$line('Kaffe', '3', '45')]))), true);

$d = HospitalityOrderDiff::between(null, $order([$line('Kaffe', '2', '45')]));
$check('added: no status row', !in_array('status', array_column($d['rows'], 'key'), true));
$same('added: hospitality appears', $d['new']['hospitality'] ?? null, 'Kantina');
$same('added: nothing before', $d['old'], []);

$d = HospitalityOrderDiff::between($order([$line('Pølse &amp;#40;stor&amp;#41;', '2', '10')]), $order([$line('Pølse &amp;#40;stor&amp;#41;', '5', '10')]));
$same('article names are decoded', array_key_first($d['new']), 'line:Pølse (stor)');

$d = HospitalityOrderDiff::between($order([$line('Kaffe', '1', '45'), $line('Kaffe', '2', '45', 'sterk')]), $order([$line('Kaffe', '1', '45'), $line('Kaffe', '4', '45', 'sterk')]));
$same('two lines with one article name stay distinct', array_keys($d['new']), ['line:Kaffe #2', 'total']);
$same('a line comment is part of the value', $d['new']['line:Kaffe #2'] ?? null, '4 stk × 45.00 = 180.00 — sterk');

$cancelled = HospitalityOrderDiff::between($order([$line('Kaffe', '3', '45')]), $order([$line('Kaffe', '3', '45')], ['status' => 'cancelled']));
$same('cancel: the status row', [$cancelled['old']['status'] ?? null, $cancelled['new']['status'] ?? null], ['pending', 'cancelled']);
$sum = HospitalityOrderDiff::summary($order([$line('Kaffe', '3', '45')], ['comment' => 'x']));
$same('summary leaves out status and empty values', array_column($sum, 'key'), ['hospitality', 'serving_time', 'location', 'comment', 'line:Kaffe', 'total']);

// ======================================================================================================
// 3. CateringMailRenderer — real template, real .lang files, language swap
// ======================================================================================================
$payload = static fn(string $kind, array $over = []): array => array_replace_recursive([
    'kind' => $kind,
    'actor' => 'Kari Nordmann',
    'application' => ['id' => 84091, 'id_string' => '84091', 'name' => 'Sommerfest', 'building_name' => 'Bygg A', 'activity_name' => 'Fest', 'organization_name' => null],
    'order' => ['id' => 7, 'serving_time_iso' => '2026-11-10 13:00:00'],
    'fallback_date' => '2026-12-01 12:00:00',
    'diff' => HospitalityOrderDiff::between($order([$line('Kaffe', '3', '45')]), $order([$line('Kaffe', '4', '45')])),
    'summary' => HospitalityOrderDiff::summary($order([$line('Kaffe', '3', '45')])),
    'links' => ['case' => 'https://x.test/booking/view/applications/84091', 'order' => 'https://x.test/booking/view/hospitality-orders/7?application_id=84091&b=2'],
], $over);

$switch = new FakeLangSwitch('en'); // what a citizen browsing with selected_lang=en leaves behind
$renderer = new CateringMailRenderer($switch);

$GLOBALS['__lang_missing'] = [];
$mail = $renderer->render($payload('changed'), 'no');
$same('the officer is Norwegian although the acting session is English: subject', $mail['subject'], 'Endret bevertning: Sommerfest, 10.11.2026');
$contains('...and the body says Fra/Til', $mail['body'], '<th align="left">Fra</th>');
$same('the language is put back afterwards', $switch->current(), 'en');
$same('...by swapping exactly there and back', $switch->calls, ['no', 'en']);
$contains('the body shows the from value', $mail['body'], '3 stk × 45.00 = 135.00');
$contains('the body shows the to value', $mail['body'], '4 stk × 45.00 = 180.00');
$contains('the body labels the article line with the article name', $mail['body'], '<td valign="top">Kaffe</td>');
$contains('the case link is a link (escaped once in the attribute)', $mail['body'], 'href="https://x.test/booking/view/hospitality-orders/7?application_id=84091&amp;b=2"');
$same('no translation key was missing while rendering (changed, no)', $GLOBALS['__lang_missing'], []);

foreach (['no', 'nn', 'en'] as $l) {
    foreach (['added', 'changed', 'cancelled'] as $kind) {
        $GLOBALS['__lang_missing'] = [];
        $m = $renderer->render($payload($kind), $l);
        $same("no missing key: {$l}/{$kind}", $GLOBALS['__lang_missing'], []);
        $check("no '!key' leaked into {$l}/{$kind}", !preg_match('/![a-z_]+/', $m['subject'] . strip_tags($m['body'])), $m['subject']);
    }
}
$en = $renderer->render($payload('changed'), 'en');
$same('English officer, subject', $en['subject'], 'Catering changed: Sommerfest, 10.11.2026');
$contains('English officer, body', $en['body'], '<th align="left">From</th>');
$nn = $renderer->render($payload('cancelled'), 'nn');
$same('Nynorsk officer, cancelled subject', $nn['subject'], 'Avbestilt bevertning: Sommerfest, 10.11.2026');
$contains('a cancelled order lists what it contained', $nn['body'], '3 stk × 45.00 = 135.00');
$added = $renderer->render($payload('added'), 'no');
$same('added subject', $added['subject'], 'Ny bevertning: Sommerfest, 10.11.2026');

// R1: encoded names — decode first, escape once; the subject is plain text
$enc = $renderer->render($payload('changed', [
    'application' => ['name' => 'Hall &amp;#40;A&amp;#41; &amp; Co', 'building_name' => 'Fløyen &amp;#40;Tubakuba&amp;#41;'],
]), 'no');
$same('R1 subject: decoded, not escaped', $enc['subject'], 'Endret bevertning: Hall (A) & Co, 10.11.2026');
$lacks('R1 subject: no entity left', $enc['subject'], '&amp;');
$contains('R1 body: the name is decoded then escaped exactly once', $enc['body'], 'Hall (A) &amp; Co');
$contains('R1 body: the building is decoded', $enc['body'], 'Fløyen (Tubakuba)');
$lacks('R1 body: never double-escaped', $enc['body'], '&amp;amp;');
$lacks('R1 body: no raw encoded paren', $enc['body'], '&amp;#40;');
$lacks('R1 body: no half-decoded paren', $enc['body'], '&#40;');

// hostile citizen text is inert
$hostile = $renderer->render($payload('changed', [
    'actor' => '<img src=x onerror=alert(1)>',
    'diff' => ['rows' => [['key' => 'comment', 'label' => null, 'from' => '', 'to' => '<script>alert(1)</script> <a href="http://evil">x</a>']]],
]), 'no');
$lacks('a <script> in a comment is not live', $hostile['body'], '<script>');
$contains('...it is shown as text', $hostile['body'], '&lt;script&gt;alert(1)&lt;/script&gt;');
$lacks('an <a href> in a comment is not live', $hostile['body'], '<a href="http://evil">');
$lacks('an <img> in the actor name is not live', $hostile['body'], '<img src=x');
$lacks('a hostile value cannot add a header line to the subject', $renderer->render($payload('changed', ['application' => ['name' => "x\r\nBcc: a@b.c"]]), 'no')['subject'], "\n");

// the date falls back to the application's own when the order has no serving time
$fb = $renderer->render($payload('changed', ['order' => ['serving_time_iso' => null]]), 'no');
$same('subject date falls back to the application date', $fb['subject'], 'Endret bevertning: Sommerfest, 01.12.2026');

// the language is restored even when the render throws
$thrower = new class {
    public function render(string $t, array $v): string
    {
        throw new \TypeError('boom');
    }
};
$sw2 = new FakeLangSwitch('en');
$threw = false;
try {
    (new CateringMailRenderer($sw2, $thrower))->render($payload('changed'), 'no');
} catch (\TypeError $e) {
    $threw = true;
}
$check('a throwing render does propagate (the notifier is what contains it)', $threw);
$same('...and the language is back to the acting one', [$sw2->current(), $sw2->calls], ['en', ['no', 'en']]);

// ======================================================================================================
// 4. CateringChangeNotifier — recipient rules and containment
// ======================================================================================================
$logFile = tempnam(sys_get_temp_dir(), 'hd-log-');
ini_set('error_log', $logFile);
ini_set('log_errors', '1');
$logTail = static fn(): string => (string)file_get_contents($logFile);
$clearLog = static function () use ($logFile): void { file_put_contents($logFile, ''); };

class TestNotifier extends CateringChangeNotifier
{
    public array $delivered = [];
    public ?\Throwable $officerThrows = null;
    public ?\Throwable $linksThrow = null;
    public ?\Throwable $deliverThrows = null;
    public array $officer = ['email' => 'officer@example.test', 'lang' => 'en'];

    protected function loadOfficer(int $accountId): array
    {
        if ($this->officerThrows) {
            throw $this->officerThrows;
        }
        return $this->officer;
    }

    protected function buildLinks(int $applicationId, int $orderId): array
    {
        if ($this->linksThrow) {
            throw $this->linksThrow;
        }
        return ['case' => 'https://x.test/a', 'order' => 'https://x.test/o'];
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

$withOfficer = static fn(int $officerId, array $over = []): array => array_replace_recursive($payload('changed'), ['application' => ['case_officer_id' => $officerId]], $over);

$sw3 = new FakeLangSwitch('en');
$n = new TestNotifier(new CateringMailRenderer($sw3));
$same('a case officer with an address gets exactly one mail', [$n->notify($withOfficer(5)), count($n->delivered)], [true, 1]);
$same('...to that officer only', $n->delivered[0][0] ?? null, 'officer@example.test');
$same('...in the officer language (en)', $n->delivered[0][1] ?? null, 'Catering changed: Sommerfest, 10.11.2026');

$n = new TestNotifier(new CateringMailRenderer(new FakeLangSwitch('en')));
$n->officer = ['email' => 'officer@example.test', 'lang' => null];
$n->notify($withOfficer(5));
$same('no language preference -> Norwegian, whatever the citizen cookie says (cookie en)', $n->delivered[0][1] ?? null, 'Endret bevertning: Sommerfest, 10.11.2026');
$n->officer = ['email' => 'officer@example.test', 'lang' => 'xx'];
$n->delivered = [];
$n->notify($withOfficer(5));
$same('an unsupported language -> Norwegian', $n->delivered[0][1] ?? null, 'Endret bevertning: Sommerfest, 10.11.2026');

$n = new TestNotifier(new CateringMailRenderer(new FakeLangSwitch('no')));
$same('no case officer (0): no mail', [$n->notify($withOfficer(0)), count($n->delivered)], [false, 0]);
$same('no case officer (key absent): no mail', [$n->notify($payload('changed')), count($n->delivered)], [false, 0]);

$clearLog();
$n->officer = ['email' => '', 'lang' => 'no'];
$same('an officer with no address: no mail', [$n->notify($withOfficer(9)), count($n->delivered)], [false, 0]);
$contains('...and the log names whose', $logTail(), 'case officer 9 of application 84091 has no usable email address');
$n->officer = ['email' => 'not an address', 'lang' => 'no'];
$same('an unusable address: no mail', [$n->notify($withOfficer(9)), count($n->delivered)], [false, 0]);
$n->officer = ['email' => null, 'lang' => 'no'];
$same('a null address: no mail', [$n->notify($withOfficer(9)), count($n->delivered)], [false, 0]);

// containment: nothing the notifier touches may escape it, TypeError included
foreach ([
    'loading the officer throws a TypeError' => static function (TestNotifier $t): void { $t->officerThrows = new \TypeError('officer'); },
    'building the links throws a TypeError' => static function (TestNotifier $t): void { $t->linksThrow = new \TypeError('links'); },
] as $name => $arm) {
    $t = new TestNotifier(new CateringMailRenderer(new FakeLangSwitch('en')));
    $arm($t);
    $threw = false;
    $result = null;
    try {
        $result = $t->notify($withOfficer(5));
    } catch (\Throwable $e) {
        $threw = true;
    }
    $check("containment: {$name}: does not throw", !$threw);
    $same("containment: {$name}: reports no mail", [$result, count($t->delivered)], [false, 0]);
}

$sw4 = new FakeLangSwitch('en');
$t = new TestNotifier(new CateringMailRenderer($sw4, $thrower));
$threw = false;
try {
    $res = $t->notify($withOfficer(5));
} catch (\Throwable $e) {
    $threw = true;
}
$check('containment: the render throws a TypeError: notify does not throw', !$threw);
$same('...it reports no mail and the language is back', [$res ?? null, $sw4->current()], [false, 'en']);

$clearLog();
$t = new TestNotifier(new CateringMailRenderer(new FakeLangSwitch('en')));
$t->deliverThrows = new \TypeError('smtp');
$threw = false;
try {
    $res = $t->notify($withOfficer(5));
} catch (\Throwable $e) {
    $threw = true;
}
$check('containment: the send throws a TypeError: notify does not throw', !$threw);
$contains('...and the failure is logged, not lost', $logTail(), 'failed: smtp');

// The notification boundary catches Throwable, never Exception alone (the PHP 8 Error trap):
// CateringChangeNotifier is what stands between a mail failure and the committed catering write.
// (The renderer and the diff have narrow \Exception catches around DateTimeImmutable parsing that
// fall through to a fallback; an Error there is not swallowed, it reaches the notifier's catch.)
$notifierSource = (string)file_get_contents($root . '/src/modules/bookingfrontend/services/applications/CateringChangeNotifier.php');
$same('the notifier has no catch (Exception ...)', preg_match_all('/catch\s*\(\s*\\\\?Exception\b/', $notifierSource), 0);
$check('the notifier catches Throwable twice: around the whole notification and inside the deferred send', preg_match_all('/catch\s*\(\s*\\\\Throwable\b/', $notifierSource) >= 2);
$control = preg_match_all('/catch\s*\(\s*\\\\?Exception\b/', (string)file_get_contents($root . '/src/modules/bookingfrontend/controllers/applications/HospitalityOrderController.php'));
$check('control: the same pattern DOES find catch (Exception in the existing controller', $control > 0, "found {$control}");

unlink($logFile);

// ======================================================================================================
$total = $passed + count($failures);
if ($failures) {
    foreach ($failures as $f) {
        echo 'FAIL ', $f, "\n";
    }
    echo 'Mail harness: FAIL ', count($failures), " of {$total} checks\n";
    exit(1);
}
echo "Mail harness: PASS {$total} checks on PHP ", PHP_VERSION, "\n";
