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
 *   - special_requirements (the applicant's note to the kitchen): inert in the mail, with an autoescape-off failing arm
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

// A file that does not exist in the tree under test (an older base) is reported by the checks that
// need it as a FAIL line, not as a fatal error. Run against a base, point VENDOR_AUTOLOAD at THAT
// tree's vendor/: the autoloader resolves App\ from its own tree, and another tree's would quietly
// supply the missing class.
$missingFiles = [];
foreach ([
    'phpgwapi/services/PreferencesReader.php',
    'booking/services/HospitalityDeadlineCalculator.php',
    'booking/services/CateringText.php',
    'booking/services/HospitalityOrderDiff.php',
    'booking/services/CateringChangeText.php',
    'booking/services/CateringLangSwitch.php',
    'booking/services/CateringMailRenderer.php',
    'booking/services/TranslationLangSwitch.php',
    'booking/repositories/HospitalityOrderRepository.php',
    'bookingfrontend/services/AllocationTimeChangeNotifier.php',
    'bookingfrontend/services/applications/CateringChangeNotifier.php',
    'bookingfrontend/controllers/applications/HospitalityOrderController.php',
    'booking/controllers/HospitalityOrderController.php',
] as $file) {
    if (!is_file($root . '/src/modules/' . $file)) {
        $missingFiles[] = $file;
        continue;
    }
    require_once $root . '/src/modules/' . $file;
}

use App\modules\booking\services\CateringLangSwitch;
use App\modules\booking\services\CateringChangeText;
use App\modules\booking\controllers\HospitalityOrderController as AdminOrderController;
use App\modules\booking\services\CateringMailRenderer;
use App\modules\booking\services\CateringText;
use App\modules\booking\services\HospitalityOrderDiff;
use App\modules\bookingfrontend\services\applications\CateringChangeNotifier;
use App\modules\booking\repositories\HospitalityOrderRepository;
use App\modules\booking\services\TranslationLangSwitch;
use App\modules\bookingfrontend\controllers\applications\HospitalityOrderController;
use App\modules\booking\repositories\HospitalityRepository;
use App\modules\booking\services\HospitalityOrderGuard;
use App\modules\phpgwapi\services\Preferences;
use App\modules\phpgwapi\services\Settings;

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
// 2b. The stored values carry each line's own VAT rate (GH #1425 D2), and parse back (S2)
// ======================================================================================================
$check('the tree under test has every file the harness loads', $missingFiles === [], 'missing: ' . implode(', ', $missingFiles));
$taxed = static fn(string $name, string $qty, string $price, mixed $vat, ?string $comment = null, string $unit = 'stk'): array
    => $line($name, $qty, $price, $comment, $unit) + ['tax_percent' => $vat];

$d = HospitalityOrderDiff::between(null, $order([$taxed('Vårruller', '2', '10', '25')]));
$same('D2: a line with a known rate stores it and the incl. amount of the moment', $d['new']['line:Vårruller'] ?? null, '2 stk × 10.00 = 20.00 [VAT 25% incl 25.00]');
$same('D2: the total stores the incl. amount when every rate is known', $d['new']['total'] ?? null, '20.00 [incl 25.00]');
$d = HospitalityOrderDiff::between(null, $order([$taxed('Brus', '3', '20', '15.00'), $taxed('Vann', '1', '10', 0)]));
$same('D2: a 15 % line keeps 15 (not 25)', $d['new']['line:Brus'] ?? null, '3 stk × 20.00 = 60.00 [VAT 15% incl 69.00]');
$same('D2: a 0 % line keeps its 0 (a real 0 % is not "unknown")', $d['new']['line:Vann'] ?? null, '1 stk × 10.00 = 10.00 [VAT 0% incl 10.00]');
$same('D2: the incl. total adds each line at its own rate (69.00 + 10.00)', $d['new']['total'] ?? null, '70.00 [incl 79.00]');
$d = HospitalityOrderDiff::between(null, $order([$taxed('Kaffe', '1', '45', '25'), $taxed('Kake', '1', '20', null)]));
$same('D2: an unknown rate (NULL: no fm_ecomva row) adds nothing to the line', $d['new']['line:Kake'] ?? null, '1 stk × 20.00 = 20.00');
$same('D2: ...and the total is then ex VAT only, never a guess', $d['new']['total'] ?? null, '65.00');
$d = HospitalityOrderDiff::between($order([$taxed('Kaffe', '2', '45', '25')]), $order([$taxed('Kaffe', '2', '45', '15')]));
$same('D2: a line re-taxed is a change of that line', [$d['old']['line:Kaffe'] ?? null, $d['new']['line:Kaffe'] ?? null], ['2 stk × 45.00 = 90.00 [VAT 25% incl 112.50]', '2 stk × 45.00 = 90.00 [VAT 15% incl 103.50]']);
// øre rounding: once per line, on the line amount (the client's inclOre), never on the unit price × quantity
$same('D2: incl. rounding is once per line (3 × 136.99 at 25 % = 410.97 + 102.74)', HospitalityOrderDiff::between(null, $order([$taxed('X', '3', '136.99', '25')]))['new']['total'] ?? null, '410.97 [incl 513.71]');
// ...and the TOTAL is the sum of the lines so rounded, as billing writes them (booking
// class.async_task_update_reservation_state.inc.php :202-205, bb_purchase_order_line.tax numeric(10,2), rounded
// half away from zero per line): two lines of 0.10 at 25 % carry 0.03 + 0.03 VAT, not the 0.05 of the total.
$same('D2: the incl. total sums per-line-rounded VAT (0.20 + 0.06), not VAT on the total (0.25)', HospitalityOrderDiff::between(null, $order([$taxed('A', '1', '0.10', '25'), $taxed('B', '1', '0.10', '25')]))['new']['total'] ?? null, '0.20 [incl 0.26]');

$hasParse = method_exists(HospitalityOrderDiff::class, 'parseLine') && method_exists(HospitalityOrderDiff::class, 'parseTotal');
$check('S2: HospitalityOrderDiff can parse a stored line and total back', $hasParse);
if ($hasParse) {
    foreach ([
        'with a rate and a comment' => [$taxed('Kaffe', '2.5', '45', '25', 'sterk — og × varm', 'kg'), ['2.5', 'kg', '45.00', '112.50', '25', '140.63', 'sterk — og × varm']],
        'legacy, no rate' => [$line('Vårruller', '2', '10', null, 'each'), ['2', 'each', '10.00', '20.00', null, null, '']],
        'no unit' => [$taxed('Leie', '1', '100', '0', null, ''), ['1', '', '100.00', '100.00', '0', '100.00', '']],
    ] as $name => [$l, $want]) {
        $stored = HospitalityOrderDiff::between(null, $order([$l]))['new']['line:' . $l['article_name']] ?? '';
        $p = HospitalityOrderDiff::parseLine($stored);
        $same("S2 parseLine round-trip ({$name}): «{$stored}»", $p === null ? null : [$p['quantity'], $p['unit'], $p['unit_price'], $p['amount'], $p['vat'], $p['incl'], $p['comment']], $want);
    }
    $same('S2 parseLine: the exact legacy value live order 122 holds', HospitalityOrderDiff::parseLine('2 each × 10.00 = 20.00')['unit'] ?? null, 'each');
    $same('S2 parseLine: not a line -> null (shown as it is)', HospitalityOrderDiff::parseLine('Kaffe'), null);
    $same('S2 parseTotal: legacy', HospitalityOrderDiff::parseTotal('20.00'), ['amount' => '20.00', 'incl' => null]);
    $same('S2 parseTotal: with incl', HospitalityOrderDiff::parseTotal('20.00 [incl 25.00]'), ['amount' => '20.00', 'incl' => '25.00']);
    $same('S2 parseTotal: not a total -> null', HospitalityOrderDiff::parseTotal('tjue'), null);
}

// ======================================================================================================
// 2c. CateringChangeText — the one formatter behind the Endringslogg, the Historikk and the mail (S2)
// ======================================================================================================
$hasText = class_exists(CateringChangeText::class);
$check('S2: the formatter CateringChangeText exists', $hasText);
$nb = "\u{00A0}";
if ($hasText) {
    $ctx = ['hospitality' => 'Fana kulturhus servering', 'serving_time' => '04.06.2029 15:00', 'location' => 'Anretning'];
    $addedEntry = static fn(array $new = []): array => [
        'kind' => 'added', 'actor' => 'Kari Nordmann', 'changed_at' => '2026-11-18T11:38:00+01:00', 'old' => [],
        'new' => $new + ['hospitality' => 'Fana kulturhus servering', 'serving_time' => '04.06.2029 15:00', 'location' => 'Anretning',
            'line:Vårruller' => '2 each × 10.00 = 20.00 [VAT 25%]', 'total' => '20.00 [incl 25.00]'],
    ];

    $GLOBALS['__lang_now'] = 'no';
    $GLOBALS['__lang_missing'] = [];
    $t = CateringChangeText::describe($addedEntry(), $ctx, 'no');
    $same('S2 no: the title says what it was', $t['title'], 'Lagt til av søker');
    $same('S2 no: who and when, in venue time with a Norwegian month', $t['byline'], 'Kari Nordmann, 18. november 2026 kl. 11:38');
    $same('S2 no: the order it is about', $t['context'], 'Fana kulturhus servering · 4. juni 2029 kl. 15:00 · Anretning');
    $same('S2 no: a line incl. VAT at its own rate, in kroner, unit translated', $t['lines'][0] ?? null, "Vårruller: 2 stk × 12,50 kr = 25 kr inkl. mva");
    $same('S2 no: the total incl. VAT', $t['lines'][1] ?? null, 'Sum: 25 kr inkl. mva');
    $same('S2 no: nothing else', count($t['lines']), 2);
    $same('S2 no: no translation key missing', $GLOBALS['__lang_missing'], []);

    // The legacy entry exactly as live order 122 holds it: no rate stored. Shown ex VAT and SAID so; never as
    // 12,50 (which is what an assumed 25 % would give).
    $legacy = CateringChangeText::describe(['kind' => 'added', 'actor' => '', 'changed_at' => null, 'old' => [],
        'new' => ['line:Vårruller' => '2 each × 10.00 = 20.00', 'total' => '20.00']], $ctx, 'no');
    $same('S2 legacy line: ex VAT, marked «ekskl. mva»', $legacy['lines'][0] ?? null, 'Vårruller: 2 stk × 10 kr = 20 kr ekskl. mva');
    $lacks('S2 legacy line: never an assumed 25 % (12,50)', implode("\n", $legacy['lines']), '12,50');
    $same('S2 legacy total: ex VAT, marked', $legacy['lines'][1] ?? null, 'Sum: 20 kr ekskl. mva');
    $same('S2 legacy: no actor, no time -> the title alone', $legacy['heading'], 'Lagt til av søker');
    $moved = CateringChangeText::describe($addedEntry(), ['serving_time' => '05.06.2029 09:00'] + $ctx, 'no');
    $same('S2: an addition shows the order as it was ADDED, not as it is now', $moved['context'], 'Fana kulturhus servering · 4. juni 2029 kl. 15:00 · Anretning');

    $rates = CateringChangeText::describe(['kind' => 'added', 'old' => [], 'new' => [
        'line:Brus' => '1 stk × 100.00 = 100.00 [VAT 15%]',
        'line:Vann' => '1 stk × 10.00 = 10.00 [VAT 0%]',
        'line:Buffet' => '16 stk × 80.00 = 1280.00 [VAT 25%]',
        'line:Ost' => '0.5 kg × 3.33 = 1.67 [VAT 25%] — uten nøtter',
    ]], $ctx, 'no');
    $same('S2: a 15 % line is 115 kr (not 125)', $rates['lines'][0] ?? null, 'Brus: 1 stk × 115 kr = 115 kr inkl. mva');
    $same('S2: a 0 % line keeps its amount and is incl.', $rates['lines'][1] ?? null, 'Vann: 1 stk × 10 kr = 10 kr inkl. mva');
    $same('S2: thousands are grouped («1 600 kr»)', $rates['lines'][2] ?? null, "Buffet: 16 stk × 100 kr = 1{$nb}600 kr inkl. mva");
    $same('S2: decimals with a comma, the line rounded once, the comment kept', $rates['lines'][3] ?? null, 'Ost: 0,5 kg × 4,16 kr = 2,09 kr inkl. mva — uten nøtter');
    // D2 (47066/47068): an entry shows the incl. amount it STORED when it was written, not one worked out again
    // today (here deliberately different from what the rule gives now, 125.00, so the two cannot be confused).
    $stored = CateringChangeText::describe(['kind' => 'added', 'old' => [], 'new' => ['line:Leie' => '1 stk × 100.00 = 100.00 [VAT 25% incl 124.00] — x']], $ctx, 'no');
    $same('S2: the stored incl. amount is the one shown', $stored['lines'][0] ?? null, 'Leie: 1 stk × 125 kr = 124 kr inkl. mva — x');
    $fresh = HospitalityOrderDiff::between(null, $order([$taxed('Leie', '1', '100', '25')]))['new']['line:Leie'] ?? '';
    $same('S2: a value written today round-trips to the same words', CateringChangeText::describe(['kind' => 'added', 'old' => [], 'new' => ['line:Leie' => $fresh]], $ctx, 'no')['lines'][0] ?? null, 'Leie: 1 stk × 125 kr = 125 kr inkl. mva');

    $changed = CateringChangeText::describe(['kind' => 'changed', 'actor' => 'Kari Nordmann', 'changed_at' => '2029-06-01T08:00:00+00:00',
        'old' => ['serving_time' => '04.06.2029 13:00', 'line:Vårruller' => '2 each × 10.00 = 20.00 [VAT 25%]', 'comment' => 'Uten nøtter', 'total' => '20.00 [incl 25.00]'],
        'new' => ['serving_time' => '04.06.2029 15:00', 'line:Vårruller' => '3 each × 10.00 = 30.00 [VAT 25%]', 'total' => '30.00 [incl 37.50]']], $ctx, 'no');
    $same('S2 change: the time from a UTC instant is venue time (summer, UTC+2)', $changed['byline'], 'Kari Nordmann, 1. juni 2029 kl. 10:00');
    $same('S2 change: the order it is about comes from the order as it is now', $changed['context'], 'Fana kulturhus servering · 4. juni 2029 kl. 15:00 · Anretning');
    $same('S2 change: rows in a stable order, each FROM → TO', $changed['lines'], [
        'Serveringstid: 4. juni 2029 kl. 13:00 → 4. juni 2029 kl. 15:00',
        'Kommentar: Uten nøtter → —',
        'Vårruller: 2 stk × 12,50 kr = 25 kr inkl. mva → 3 stk × 12,50 kr = 37,50 kr inkl. mva',
        'Sum: 25 kr inkl. mva → 37,50 kr inkl. mva',
    ]);
    $winter = CateringChangeText::describe(['kind' => 'cancelled', 'changed_at' => '2026-03-29T00:30:00+00:00', 'old' => ['status' => 'pending'], 'new' => ['status' => 'cancelled']], $ctx, 'no');
    $same('S2 the DST night: 00:30 UTC on 29 March is 01:30 local (UTC+1 still)', $winter['byline'], '29. mars 2026 kl. 01:30');
    $midnight = CateringChangeText::describe(['kind' => 'cancelled', 'changed_at' => '2026-11-17T23:30:00+00:00', 'old' => [], 'new' => []], $ctx, 'no');
    $same('S2: the DATE is venue time too (23:30 UTC on the 17th is the 18th in Oslo)', $midnight['byline'], '18. november 2026 kl. 00:30');
    $same('S2 cancel: the title', $winter['title'], 'Avbestilt av søker');
    $same('S2 cancel: the status is not repeated as a line', $winter['lines'], []);

    $GLOBALS['__lang_now'] = 'en';
    $en = CateringChangeText::describe($addedEntry(), $ctx, 'en');
    $same('S2 en: title', $en['title'], 'Added by the applicant');
    $same('S2 en: date', $en['byline'], 'Kari Nordmann, 18 November 2026 at 11:38');
    $same('S2 en: amounts', $en['lines'][0] ?? null, 'Vårruller: 2 each × NOK 12.50 = NOK 25 incl. VAT');
    $GLOBALS['__lang_now'] = 'nn';
    $nn = CateringChangeText::describe($addedEntry(), $ctx, 'nn');
    $same('S2 nn: title', $nn['title'], 'Lagt til av søkjar');
    $GLOBALS['__lang_now'] = 'no';

    foreach (['no', 'nn', 'en'] as $l) {
        foreach (['added', 'changed', 'cancelled'] as $k) {
            $GLOBALS['__lang_now'] = $l;
            $GLOBALS['__lang_missing'] = [];
            $all = CateringChangeText::describe(['kind' => $k, 'actor' => 'A', 'changed_at' => '2026-11-18T11:38:00+01:00',
                'old' => $k === 'changed' ? ['comment' => 'a', 'line:X' => '1 stk × 1.00 = 1.00', 'total' => '1.00'] : [],
                'new' => ['comment' => 'b', 'special_requirements' => 'c', 'line:X' => '2 stk × 1.00 = 2.00 [VAT 25%]', 'total' => '2.00 [incl 2.50]', 'status' => 'pending']], $ctx, $l);
            $same("S2 {$l}/{$k}: no translation key missing", $GLOBALS['__lang_missing'], []);
            $check("S2 {$l}/{$k}: no '!key' leaked", !preg_match('/![a-z_]+/', implode("\n", $all['lines']) . $all['heading'] . $all['context']));
        }
    }
    $GLOBALS['__lang_now'] = 'no';

    $same('S2 kind: from the stored comment (added)', CateringChangeText::kindOf('Order added by applicant via Min side', ['x' => '1'], []), 'added');
    $same('S2 kind: from the stored comment (changed)', CateringChangeText::kindOf('Order changed by applicant via Min side', [], ['x' => '1']), 'changed');
    $same('S2 kind: from the stored comment (cancelled)', CateringChangeText::kindOf('Order cancelled by applicant via Min side', [], []), 'cancelled');
    $same('S2 kind: unknown comment, a status of cancelled', CateringChangeText::kindOf('?', ['status' => 'pending'], ['status' => 'cancelled']), 'cancelled');
    $same('S2 kind: unknown comment, nothing before', CateringChangeText::kindOf(null, [], ['x' => '1']), 'added');
    $same('S2 kind: the controller writes exactly the comments the kind is read from',
        array_values(CateringChangeText::ENTRY_COMMENTS),
        ['Order added by applicant via Min side', 'Order changed by applicant via Min side', 'Order cancelled by applicant via Min side']);
    $unknown = CateringChangeText::describe(['kind' => 'changed', 'old' => ['mystery' => 'a'], 'new' => ['mystery' => 'b']], $ctx, 'no');
    $same('S2: a key the formatter does not know is shown, not dropped', $unknown['lines'], ['mystery: a → b']);
    $raw = CateringChangeText::describe(['kind' => 'added', 'old' => [], 'new' => ['line:X' => 'not a line value']], $ctx, 'no');
    $same('S2: a line value it cannot parse is shown as it is', $raw['lines'], ['X: not a line value']);
    $plain = CateringChangeText::describe(['kind' => 'added', 'old' => [], 'new' => ['comment' => '<b>x</b> &amp;']], $ctx, 'no');
    $same('S2: output is plain text, NOT escaped here (the caller escapes once)', $plain['lines'], ['Kommentar: <b>x</b> &amp;']);
    $same('S2 context from a live order snapshot (contextOf)', CateringChangeText::contextOf($order([], ['hospitality_name' => 'Kantina &amp;#40;A&amp;#41;', 'serving_time_iso' => '2029-06-04 13:00:00', 'location_name' => 'Storsal'])),
        ['hospitality' => 'Kantina (A)', 'serving_time' => '04.06.2029 15:00', 'location' => 'Storsal']);
}

// ======================================================================================================
// 2d. S3 — the admin catering list in serving order; admin «Endringslogg» display (S2)
// ======================================================================================================
$hasSort = method_exists(AdminOrderController::class, 'sortByServingTime');
$check('S3: the admin controller sorts the catering list by serving time', $hasSort);
if ($hasSort) {
    // The live shape that was wrong (#46220): the NEWEST order (highest id) has the LATEST serving time and stood first.
    $rows = [
        ['id' => 122, 'serving_time_iso' => '2029-06-04 13:00:00'],
        ['id' => 112, 'serving_time_iso' => '2029-06-04 11:30:00'],
        ['id' => 130, 'serving_time_iso' => null],
        ['id' => 107, 'serving_time_iso' => '2029-06-04 12:00:00'],
        ['id' => 108, 'serving_time_iso' => '2029-06-04 12:00:00'],
    ];
    $same('S3: earliest serving first, no serving time last, ties by id', array_column(AdminOrderController::sortByServingTime($rows), 'id'), [112, 107, 108, 122, 130]);
}
// The index (needs a database to run) sorts on the application_id path only: the kitchen's hospitality_id
// list and the repository default (which also feeds Min side) keep their order.
$adminSrc = is_file($root . '/src/modules/booking/controllers/HospitalityOrderController.php') ? (string)file_get_contents($root . '/src/modules/booking/controllers/HospitalityOrderController.php') : '';
$same('S3: the index sorts exactly once', preg_match_all('/\$orders = self::sortByServingTime\(\$orders\);/', $adminSrc), 1);
$check('S3: ...inside the application_id branch, before the hospitality_id branch',
    preg_match('/getOrdersWithLinesByApplication\(\(int\)\$params\[\'application_id\'\]\);\s*\}\s*(?:\/\/[^\n]*\n\s*)*\$orders = self::sortByServingTime\(\$orders\);\s*\} elseif \(!empty\(\$params\[\'hospitality_id\'\]\)\)/', $adminSrc) === 1);
$same('S3: the repository default is untouched (created DESC, also Min side\'s)', preg_match_all('/ORDER BY o\.created DESC/', $repoSource ?? (string)file_get_contents($root . '/src/modules/booking/repositories/HospitalityOrderRepository.php')), 3);
$adminRef = new \ReflectionClass(AdminOrderController::class);
$hasDisplay = $adminRef->hasMethod('withReadableChangelog');
$check('S2: the admin order JSON puts applicant entries into words', $hasDisplay);
if ($hasDisplay && $hasText) {
    $admin = $adminRef->newInstanceWithoutConstructor();
    $snap = $order([$taxed('Vårruller', '3', '10', '25')], ['hospitality_name' => 'Fana kulturhus servering', 'serving_time_iso' => '2029-06-04 13:00:00', 'location_name' => 'Anretning']);
    $snap['changelog'] = [
        ['id' => 2, 'change_type' => 'applicant_change', 'comment' => 'Order changed by applicant via Min side', 'booking_user_name' => 'Kari Nordmann',
            'changed_at' => '2026-11-18T11:38:00+01:00', 'old_value' => ['line:Vårruller' => '2 each × 10.00 = 20.00'], 'new_value' => ['line:Vårruller' => '3 each × 10.00 = 30.00 [VAT 25%]']],
        ['id' => 1, 'change_type' => 'field_update', 'comment' => 'Officer note', 'case_officer_name' => 'henning', 'changed_at' => '2026-11-18T10:00:00+01:00',
            'old_value' => ['comment' => 'a'], 'new_value' => ['comment' => 'b']],
    ];
    $GLOBALS['__lang_now'] = 'no';
    $out = $adminRef->getMethod('withReadableChangelog')->invoke($admin, $snap, 'no');
    $same('S2 admin: an applicant entry gets its title', $out['changelog'][0]['display']['title'] ?? null, 'Endret av søker');
    $same('S2 admin: ...and its lines (a legacy FROM ex VAT, a new TO incl. VAT)', $out['changelog'][0]['display']['lines'] ?? null,
        ['Vårruller: 2 stk × 10 kr = 20 kr ekskl. mva → 3 stk × 12,50 kr = 37,50 kr inkl. mva']);
    $same('S2 admin: ...the stored entry itself is unchanged', [$out['changelog'][0]['comment'], $out['changelog'][0]['old_value']], [$snap['changelog'][0]['comment'], $snap['changelog'][0]['old_value']]);
    $check('S2 admin: an officer entry gets NO display (rendered as before)', !array_key_exists('display', $out['changelog'][1]));
    $snap['changelog'][0]['old_value'] = 'not a map';
    $out = $adminRef->getMethod('withReadableChangelog')->invoke($admin, $snap, 'no');
    $check('S2 admin: an odd stored value does not throw and still gets words', isset($out['changelog'][0]['display']['title']));
    // No language to be had (here: no Translation singleton outside the app, an Error, not an Exception — and
    // show() catches Exception only): the order is returned as it was, raw, and the page still loads.
    $threw = null;
    $quietLog = ini_set('error_log', '/dev/null');
    $quietLevel = error_reporting(0);   // the app's Settings warns while it fails to boot here; not what is checked
    try {
        $out = $adminRef->getMethod('withReadableChangelog')->invoke($admin, $snap, null);
    } catch (\Throwable $e) {
        $threw = $e;
    } finally {
        error_reporting($quietLevel);
        ini_set('error_log', $quietLog === false ? '' : $quietLog);
    }
    $check('S2 admin: no language available -> no throw', $threw === null, $threw ? get_class($threw) . ': ' . $threw->getMessage() : '');
    $check('S2 admin: ...and the entries are left raw', $threw === null && !array_key_exists('display', $out['changelog'][0]));
}

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
    'changed_at' => '2026-11-08T09:15:00+01:00',
    'context' => ['hospitality' => 'Kantina', 'serving_time' => '10.11.2026 14:00', 'location' => 'Storsal'],
    'cancel_deadline' => '2026-11-09T14:00:00+01:00',
], $over);

$switch = new FakeLangSwitch('en'); // what a citizen browsing with selected_lang=en leaves behind
$renderer = new CateringMailRenderer($switch);

$GLOBALS['__lang_missing'] = [];
$mail = $renderer->render($payload('changed'), 'no');
$same('the officer is Norwegian although the acting session is English: subject', $mail['subject'], 'Endret bevertning: Sommerfest, 10.11.2026');
$contains('...and the body is the Endringslogg\'s words, in Norwegian', $mail['body'], '<b>Endret av søker — Kari Nordmann, 8. november 2026 kl. 09:15</b>');
$contains('...with the order it is about', $mail['body'], 'Kantina · 10. november 2026 kl. 14:00 · Storsal');
$same('the language is put back afterwards', $switch->current(), 'en');
$same('...by swapping exactly there and back', $switch->calls, ['no', 'en']);
$contains('the body shows the change FROM -> TO, labelled with the article name (no rate stored: ex VAT, marked)', $mail['body'], '<li>Kaffe: 3 stk × 45 kr = 135 kr ekskl. mva → 4 stk × 45 kr = 180 kr ekskl. mva</li>');
$lacks('the old FROM/TO table is gone', $mail['body'], '<table');
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
$contains('English officer, body', $en['body'], '<b>Changed by the applicant — Kari Nordmann, 8 November 2026 at 09:15</b>');
$nn = $renderer->render($payload('cancelled'), 'nn');
$same('Nynorsk officer, cancelled subject', $nn['subject'], 'Avbestilt bevertning: Sommerfest, 10.11.2026');
$contains('a cancelled order lists what it contained', $nn['body'], '<li>Kaffe: 3 stk × 45 kr = 135 kr ekskl. mva</li>');
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
    'diff' => ['rows' => [['key' => 'comment', 'label' => null, 'from' => '', 'to' => '<script>alert(1)</script> <a href="http://evil">x</a>']],
        'old' => [], 'new' => ['comment' => '<script>alert(1)</script> <a href="http://evil">x</a>']],
]), 'no');
$lacks('a <script> in a comment is not live', $hostile['body'], '<script>');
$contains('...it is shown as text', $hostile['body'], '&lt;script&gt;alert(1)&lt;/script&gt;');
$lacks('an <a href> in a comment is not live', $hostile['body'], '<a href="http://evil">');
$lacks('an <img> in the actor name is not live', $hostile['body'], '<img src=x');
$lacks('a hostile value cannot add a header line to the subject', $renderer->render($payload('changed', ['application' => ['name' => "x\r\nBcc: a@b.c"]]), 'no')['subject'], "\n");
// The order line of the log carries stored names (kitchen, delivery place): inert as well.
$hostileCtx = $renderer->render($payload('changed', ['context' => ['hospitality' => '<img src=y onerror=alert(2)>', 'location' => '<b>x</b>']]), 'no');
$lacks('an <img> in the order line is not live', $hostileCtx['body'], '<img src=y');
$contains('...it is shown as text', $hostileCtx['body'], '&lt;img src=y onerror=alert(2)&gt; · 10. november 2026 kl. 14:00 · &lt;b&gt;x&lt;/b&gt;');
// The template's own rule, checked: no value is ever output raw (a deadline or a translated word included).
$mailTpl = (string)file_get_contents($root . '/src/modules/booking/html/base/emails/catering_changed_by_applicant.twig');
$same('the mail template has no |raw and no |safe_html anywhere', preg_match_all('/\|\s*(raw|safe_html)\b/', preg_replace('/\{#.*?#\}/s', '', $mailTpl)), 0);
$check('control: that pattern finds a |raw', preg_match('/\|\s*(raw|safe_html)\b/', '{{ deadline|raw }}') === 1);

// special_requirements: the applicant's note to the kitchen, written by the applicant and so as hostile as a comment.
// Driven through the REAL diff, summary and template, in every language, for an addition, a change and a clearing.
$srHostile = '<img src=x onerror=alert(1)> <a href="http://evil">x</a> <script>alert(2)</script>';
$srLine    = $line('Kaffe', '3', '45');
$srBefore  = $order([$srLine]);                                                       // special_requirements NULL
$srAfter   = $order([$srLine], ['special_requirements' => $srHostile]);
$srDiff    = HospitalityOrderDiff::between($srBefore, $srAfter);
$srSummary = HospitalityOrderDiff::summary($srAfter);

$same('special_requirements diff: only that key changed', array_keys($srDiff['new']), ['special_requirements']);
$same('special_requirements diff: kept as decoded PLAIN text (escaping is the template\'s job)', $srDiff['new']['special_requirements'] ?? null, $srHostile);
$same('special_requirements diff: nothing before', $srDiff['old']['special_requirements'] ?? 'absent', 'absent');
$check('special_requirements summary carries the field', in_array('special_requirements', array_column($srSummary, 'key'), true));

// The client sends null for an empty note and an officer may have left '': that is not a change.
$check('special_requirements: NULL vs "" is no change (no row, no mail)', HospitalityOrderDiff::isEmpty(HospitalityOrderDiff::between($srBefore, $order([$srLine], ['special_requirements' => '']))));
// Clearing a stored note: it is in OLD and absent from NEW.
$srClear = HospitalityOrderDiff::between($srAfter, $srBefore);
$same('special_requirements clear: old holds the text, new has no key', [$srClear['old']['special_requirements'] ?? null, $srClear['new']['special_requirements'] ?? 'absent'], [$srHostile, 'absent']);

$srInertAsserts = static function (string $tag, string $body, string $l) use ($contains, $lacks): void {
    $lacks("special_requirements {$tag} {$l}: <img> is not live", $body, '<img src=x');
    $lacks("special_requirements {$tag} {$l}: <a href> is not live (HTML injection without JS)", $body, '<a href="http://evil"');
    $lacks("special_requirements {$tag} {$l}: <script> is not live", $body, '<script>');
    $contains("special_requirements {$tag} {$l}: shown as text (img)", $body, '&lt;img src=x onerror=alert(1)&gt;');
    $contains("special_requirements {$tag} {$l}: shown as text (a)", $body, '&lt;a href=&quot;http://evil&quot;&gt;x&lt;/a&gt;');
    $lacks("special_requirements {$tag} {$l}: escaped exactly once", $body, '&amp;lt;');
    $label = $GLOBALS['__lang_table'][$l]['booking']['catering_field_special_requirements'] ?? '(missing)';
    $contains("special_requirements {$tag} {$l}: the line carries its translated label «{$label}»", $body, '<li>' . $label . ': ');
};

foreach (['no', 'nn', 'en'] as $l) {
    $GLOBALS['__lang_missing'] = [];
    $m = $renderer->render(array_merge($payload('changed'), ['diff' => $srDiff]), $l);
    $srInertAsserts('changed', $m['body'], $l);
    $same("special_requirements changed {$l}: no translation key missing", $GLOBALS['__lang_missing'], []);
    $check("special_requirements changed {$l}: no '!key' leaked", !preg_match('/![a-z_]+/', $m['subject'] . strip_tags($m['body'])));

    $GLOBALS['__lang_missing'] = [];
    $m = $renderer->render(array_merge($payload('added'), ['summary' => $srSummary]), $l);
    $srInertAsserts('added', $m['body'], $l);
    $same("special_requirements added {$l}: no translation key missing", $GLOBALS['__lang_missing'], []);

    // The clearing: FROM holds the (escaped) text, TO reads as the "nothing" dash.
    $m = $renderer->render(array_merge($payload('changed'), ['diff' => $srClear]), $l);
    $contains("special_requirements clear {$l}: FROM is the escaped text", $m['body'], '&lt;img src=x onerror=alert(1)&gt;');
    $contains("special_requirements clear {$l}: TO reads as nothing", $m['body'], ' → ' . $GLOBALS['__lang_table'][$l]['booking']['catering_mail_nothing'] . '</li>');
}

// The failing arm: the SAME template and payload with autoescape OFF must put the hostile tags live, otherwise the
// lacks() checks above could not have failed. The template source goes through an ArrayLoader on purpose: Twig
// compiles the escaping INTO the template class, and with cache=false two environments that resolve the same
// template (here: a FilesystemLoader path) share ONE compiled class, so a second environment would silently reuse
// the escaped one and this mutant would stay escaped, passing nothing and proving nothing.
$srTplName    = '@views/emails/catering_changed_by_applicant.twig';
$srLoader     = new \Twig\Loader\ArrayLoader([$srTplName => file_get_contents($root . '/src/modules/booking/html/base/emails/catering_changed_by_applicant.twig')]);
$srUnsafeTwig = new \Twig\Environment($srLoader, ['autoescape' => false, 'strict_variables' => false]);
$srUnsafe = new class($srUnsafeTwig) {
    public function __construct(private \Twig\Environment $twig) {}
    public function render(string $template, array $vars): string { return $this->twig->render($template, $vars); }
};
$srMutant = (new CateringMailRenderer(new FakeLangSwitch('en'), $srUnsafe))->render(array_merge($payload('changed'), ['diff' => $srDiff]), 'no');
$check('special_requirements failing arm (autoescape OFF): the <img> IS live, so the lacks() checks can fail', str_contains($srMutant['body'], '<img src=x onerror=alert(1)>'));
$check('special_requirements failing arm (autoescape OFF): the <a href> IS live', str_contains($srMutant['body'], '<a href="http://evil">'));
$check('special_requirements failing arm (autoescape OFF): the escaped form is ABSENT', !str_contains($srMutant['body'], '&lt;img src=x'));
$GLOBALS['__lang_now'] = 'en'; // FakeLangSwitch's constructor set the acting language; put it back

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
// 3b. S5 — the cancellation deadline line, in the OFFICER's language (pe-pm 46234 condition 3)
// ======================================================================================================
$dlSwitch = new FakeLangSwitch('en');   // the citizen browses in English
$dl = new CateringMailRenderer($dlSwitch);
$m = $dl->render($payload('changed', ['cancel_deadline' => '2029-06-02T15:00:00+02:00']), 'no');
$contains('S5: the deadline, in the officer\'s Norwegian although the acting session is English', $m['body'], '<b>Avbestillingsfrist: 2. juni 2029 kl. 15:00</b>');
$lacks('S5: ...no English in it (the session language would have given «June»)', $m['body'], 'June');
$same('S5: ...and the acting language is back afterwards', $dlSwitch->current(), 'en');
$contains('S5: an English officer', $dl->render($payload('added', ['cancel_deadline' => '2029-06-02T15:00:00+02:00']), 'en')['body'], '<b>Cancellation deadline: 2 June 2029 at 15:00</b>');
$contains('S5: a Nynorsk officer', $dl->render($payload('changed', ['cancel_deadline' => '2029-06-02T15:00:00+02:00']), 'nn')['body'], '<b>Avbestillingsfrist: 2. juni 2029 kl. 15:00</b>');
$contains('S5: an instant in UTC is shown in venue time', $dl->render($payload('changed', ['cancel_deadline' => '2029-06-02T13:00:00+00:00']), 'no')['body'], 'Avbestillingsfrist: 2. juni 2029 kl. 15:00');
$lacks('S5: no deadline line on a cancellation', $dl->render($payload('cancelled', ['cancel_deadline' => '2029-06-02T15:00:00+02:00']), 'no')['body'], 'Avbestillingsfrist');
$lacks('S5: no deadline line when the guard gives none', $dl->render(array_merge($payload('changed'), ['cancel_deadline' => null]), 'no')['body'], 'Avbestillingsfrist');
$lacks('S5: an unreadable deadline is left out, not guessed', $dl->render($payload('changed', ['cancel_deadline' => 'not a time']), 'no')['body'], 'Avbestillingsfrist');
$GLOBALS['__lang_now'] = 'en';

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

    // D4 switch and D1 group: only the database reads are replaced; recipients() itself is the real one.
    public bool $enabled = true;
    public array $groupIds = [];
    public array $prefRows = [];
    public array $groupCalls = [];
    public array $prefCalls = [];

    protected function notificationsEnabled(): bool
    {
        return $this->enabled;
    }

    protected function loadBuildingCaseOfficerIds(int $buildingId): array
    {
        $this->groupCalls[] = $buildingId;
        return $this->groupIds;
    }

    protected function readCommonPreferenceRows(array $accountIds): array
    {
        $this->prefCalls[] = $accountIds;
        return $this->prefRows;
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
$contains('...and the failure is logged, not lost, by the send\'s own catch', $logTail(), 'Catering change mail for application 84091 failed: smtp');

// ======================================================================================================
// 4c. D1 — no case officer to mail: the building's case officers; D4 — the switch (task 47036)
// ======================================================================================================
$pref = static fn(int|string $owner, array $json): array => ['preference_owner' => $owner, 'preference_json' => json_encode($json, JSON_UNESCAPED_UNICODE)];
$subjectIn = static fn(string $l, string $kind = 'changed'): string => str_replace(['%1', '%2'], ['Sommerfest', '10.11.2026'], $GLOBALS['__lang_table'][$l]['booking']["catering_mail_subject_{$kind}"]);
$groupNotifier = static function (array $ids, array $rows): TestNotifier {
    $n = new TestNotifier(new CateringMailRenderer(new FakeLangSwitch('en')));
    $n->groupIds = $ids;
    $n->prefRows = $rows;
    return $n;
};
$noOfficer = $withOfficer(0, ['application' => ['building_id' => 10]]);

$n = $groupNotifier([3106, 3097], [$pref(-2, ['lang' => 'no']), $pref(3097, ['email' => 'a@example.test', 'lang' => 'nn']), $pref(3106, ['email' => 'b@example.test', 'lang' => 'en'])]);
$same('D1: no case officer -> one mail per case officer on the building', [$n->notify($noOfficer), count($n->delivered)], [true, 2]);
$same('D1: ...asked about the application\'s own building', $n->groupCalls, [10]);
$same('D1: ...lowest account id first, each to their own address', array_column($n->delivered, 0), ['a@example.test', 'b@example.test']);
$same('D1: ...each in their OWN language (nn, en), not the acting session\'s', array_column($n->delivered, 1), [$subjectIn('nn'), $subjectIn('en')]);
$same('D1: ...their preferences read in ONE query for all of them', $n->prefCalls, [[3097, 3106]]);

$n = $groupNotifier([3097, 3106], [$pref(-1, ['lang' => 'no']), $pref(3097, ['email' => 'a@example.test', 'lang' => 'nn']), $pref(3106, ['email' => 'b@example.test', 'lang' => 'en'])]);
$n->notify($noOfficer);
$same('D1: a FORCED language (owner -1) wins for every one of them', array_column($n->delivered, 1), [$subjectIn('no'), $subjectIn('no')]);

$n = $groupNotifier([3097, 3106], [$pref(3097, ['email' => 'a@example.test']), $pref(3106, ['email' => 'b@example.test', 'lang' => 'xx'])]);
$n->notify($noOfficer);
$same('D1: no or an unsupported language -> Norwegian', array_column($n->delivered, 1), [$subjectIn('no'), $subjectIn('no')]);

$n = $groupNotifier([4001, 4002, 4003], [$pref(4001, ['email' => ' Felles@Example.test ', 'lang' => 'nn']), $pref(4002, ['email' => 'felles@example.test', 'lang' => 'en']), $pref(4003, ['email' => 'c@example.test', 'lang' => 'en'])]);
$n->notify($noOfficer);
$same('dedup: two accounts with one address (case, spaces) -> ONE mail to it', array_column($n->delivered, 0), ['Felles@Example.test', 'c@example.test']);
$same('dedup: ...in the language of the lowest account id', $n->delivered[0][1] ?? null, $subjectIn('nn'));

$clearLog();
$n = $groupNotifier([4001, 4002], [$pref(4001, ['email' => '']), $pref(4002, ['email' => 'not an address'])]);
$same('D1: case officers without a usable address are skipped: no mail', [$n->notify($noOfficer), count($n->delivered)], [false, 0]);
$contains('D1: ...and the log says so', $logTail(), "none of building 10's case officers has a usable email address");
$clearLog();
$n = $groupNotifier([], []);
$same('D1: a building with no active case officer: no mail', [$n->notify($noOfficer), count($n->delivered), $n->prefCalls], [false, 0, []]);
$contains('D1: ...and the log says so', $logTail(), 'building 10 has no active case officer');
$n = $groupNotifier([3097], [$pref(3097, ['email' => 'a@example.test'])]);
$same('D1: no building on the application: no mail, the group is not asked', [$n->notify($withOfficer(0)), $n->groupCalls], [false, []]);

$n = $groupNotifier([3097], [$pref(3097, ['email' => 'a@example.test'])]);
$n->officer = ['email' => 'officer@example.test', 'lang' => 'no'];
$same('D1: a case officer WITH an address gets it alone; the group is never asked', [$n->notify($withOfficer(5, ['application' => ['building_id' => 10]])), array_column($n->delivered, 0), $n->groupCalls], [true, ['officer@example.test'], []]);

$clearLog();
$n = $groupNotifier([3097], [$pref(3097, ['email' => 'a@example.test', 'lang' => 'nn'])]);
$n->officer = ['email' => '', 'lang' => 'en'];
$same('47038 (1): a case officer WITHOUT a usable address -> the building\'s case officers instead', [$n->notify($withOfficer(9, ['application' => ['building_id' => 10]])), array_column($n->delivered, 0)], [true, ['a@example.test']]);
$contains('47038 (1): ...and the log names the officer and the fallback', $logTail(), "case officer 9 of application 84091 has no usable email address; mailing the building's case officers instead");

$n = $groupNotifier([3097], [$pref(3097, ['email' => 'a@example.test'])]);
$n->enabled = false;
$n->officer = ['email' => 'officer@example.test', 'lang' => 'no'];
$same('D4 OFF: the officer path sends nothing', [$n->notify($withOfficer(5, ['application' => ['building_id' => 10]])), count($n->delivered)], [false, 0]);
$same('D4 OFF: the group path sends nothing, and nobody is even looked up', [$n->notify($noOfficer), count($n->delivered), $n->groupCalls, $n->prefCalls], [false, 0, [], []]);
$hasSwitch = method_exists(CateringChangeNotifier::class, 'switchIsOn') && defined(CateringChangeNotifier::class . '::SWITCH_KEY');
$check('D4: the notifier has the switch', $hasSwitch);
if ($hasSwitch) {
    $same('D4: absent / empty / "1" are ON, the stored "0" is OFF',
        array_map([CateringChangeNotifier::class, 'switchIsOn'], [null, '', '1', 'True', '0', ' 0 ']),
        [true, true, true, true, false, false]);
    $same('D4: the key the mail settings page writes', constant(CateringChangeNotifier::class . '::SWITCH_KEY'), 'notify_case_officer_on_catering_change');
}

// one recipient whose mail cannot be composed does not cost the others theirs
$nnThrows = new class {
    public function render(string $t, array $v): string
    {
        if ($GLOBALS['__lang_now'] === 'nn') {
            throw new \TypeError('nn template');
        }
        return 'ok';
    }
};
$n = new TestNotifier(new CateringMailRenderer(new FakeLangSwitch('en'), $nnThrows));
$n->groupIds = [3097, 3106];
$n->prefRows = [$pref(3097, ['email' => 'a@example.test', 'lang' => 'nn']), $pref(3106, ['email' => 'b@example.test', 'lang' => 'en'])];
$same('D1: the first recipient\'s mail fails to compose -> the second still gets theirs', [$n->notify($noOfficer), array_column($n->delivered, 0)], [true, ['b@example.test']]);
// ...and the same for a send that fails (where the SAPI cannot fork, the sends run one after the other)
$firstSendFails = new class(new CateringMailRenderer(new FakeLangSwitch('en'))) extends TestNotifier {
    protected function deliver(string $to, string $subject, string $body): void
    {
        if ($to === 'a@example.test') {
            throw new \TypeError('smtp a');
        }
        parent::deliver($to, $subject, $body);
    }
};
$firstSendFails->groupIds = [3097, 3106];
$firstSendFails->prefRows = [$pref(3097, ['email' => 'a@example.test']), $pref(3106, ['email' => 'b@example.test'])];
$same('D1: the first recipient\'s send fails -> the second is still sent theirs', [$firstSendFails->notify($noOfficer), array_column($firstSendFails->delivered, 0)], [true, ['b@example.test']]);

// The SQL of the two group reads (static: they need a database). The role, the building, ACTIVE accounts;
// the preferences of ALL of them plus the forced and default rows, in one statement.
$notifierSrc = (string)file_get_contents($root . '/src/modules/bookingfrontend/services/applications/CateringChangeNotifier.php');
foreach (["p.object_type = 'building'", 'p.object_id = :building_id', "p.role = 'case_officer'", "a.account_status = 'A'", 'preference_owner IN (-1, -2', "REPLACE(preference_app, ' ', '') = 'common'"] as $needle) {
    $contains("D1 SQL: «{$needle}»", $notifierSrc, $needle);
}

// IDENTITY on the group path (pe-pm 47038): the citizen's request reads N officers' preferences and must
// still be the citizen's at the end. The stand-in CreateObject reproduces what the Preferences singleton
// does to Settings['user'] (Preferences.php:48-55), so a group read that goes back to it is RED.
$GLOBALS['__createobject_calls'] = [];
if (!function_exists('CreateObject')) {
    function CreateObject($class, $accountId = null)
    {
        $GLOBALS['__createobject_calls'][] = [$class, $accountId];
        if ($class === 'phpgwapi.preferences' && $accountId) {
            Preferences::getInstance()->account_id = $accountId;
            Settings::getInstance()->update('user', ['account_id' => $accountId, 'preferences' => ['common' => ['lang' => 'no', 'email' => "officer{$accountId}@example.test"]]]);
        }
        return new class {
            public function read(): array
            {
                return ['common' => ['email' => 'officer@example.test', 'lang' => 'no']];
            }
        };
    }
}
$seedCitizen = static function (): void {
    $settings = (new \ReflectionClass(Settings::class))->newInstanceWithoutConstructor();
    (new \ReflectionProperty(Settings::class, 'settings'))->setValue($settings, ['user' => ['account_id' => 1022, 'preferences' => ['common' => ['lang' => 'en']]]]);
    (new \ReflectionProperty(Settings::class, 'instance'))->setValue(null, $settings);
    $prefs = (new \ReflectionClass(Preferences::class))->newInstanceWithoutConstructor();
    $prefs->account_id = 1022;
    (new \ReflectionProperty(Preferences::class, 'instance'))->setValue(null, $prefs);
};
$identity = static fn(): array => [Settings::getInstance()->get('user'), Preferences::getInstance()->account_id];

$seedCitizen();
$GLOBALS['__createobject_calls'] = [];
$before = $identity();
$n = $groupNotifier([3097, 3106], [$pref(3097, ['email' => 'a@example.test', 'lang' => 'nn']), $pref(3106, ['email' => 'b@example.test', 'lang' => 'no'])]);
$same('identity (group path): both case officers are mailed', [$n->notify($noOfficer), count($n->delivered)], [true, 2]);
$same('identity (group path): Settings[user] is byte-equal after notify()', $identity()[0], $before[0]);
$same('identity (group path): ...and the Preferences singleton is still the citizen\'s', $identity()[1], $before[1]);
$same('identity (group path): ...the legacy CreateObject(phpgwapi.preferences) was never called', $GLOBALS['__createobject_calls'], []);
// RED control: the same notify(), with the group read going through the singleton as the legacy code did.
$singletonGroup = new class(new CateringMailRenderer(new FakeLangSwitch('en'))) extends TestNotifier {
    protected function readCommonPreferenceRows(array $accountIds): array
    {
        foreach ($accountIds as $id) {
            CreateObject('phpgwapi.preferences', $id)->read();
        }
        return parent::readCommonPreferenceRows($accountIds);
    }
};
$singletonGroup->groupIds = [3097, 3106];
$singletonGroup->prefRows = [$pref(3097, ['email' => 'a@example.test']), $pref(3106, ['email' => 'b@example.test'])];
$seedCitizen();
$before = $identity();
$singletonGroup->notify($noOfficer);
$check('identity control: a group read through the singleton DOES change Settings[user] (the probe can fail)', $identity()[0] !== $before[0]);
$seedCitizen();

// ======================================================================================================
// 4b. The time the officer reads on a case entry (found live: the admin pages showed it one hour early)
//     changed_at is naive UTC; it must reach the browser as an instant in venue time.
// ======================================================================================================
$same('case entry time: winter (UTC+1)', HospitalityOrderRepository::utcToVenueIso('2026-10-26 12:15:08.245654'), '2026-10-26T13:15:08+01:00');
$same('case entry time: summer (UTC+2)', HospitalityOrderRepository::utcToVenueIso('2026-07-01 10:00:00'), '2026-07-01T12:00:00+02:00');
$same('case entry time: the DST night', HospitalityOrderRepository::utcToVenueIso('2026-03-29 00:30:00'), '2026-03-29T01:30:00+01:00');
$same('case entry time: null stays null', HospitalityOrderRepository::utcToVenueIso(null), null);
$same('case entry time: unparseable is left as it was', HospitalityOrderRepository::utcToVenueIso('not a time'), 'not a time');
$same('case entry time: the instant is unchanged, only the zone is', (new \DateTimeImmutable(HospitalityOrderRepository::utcToVenueIso('2026-10-26 12:15:08')))->getTimestamp(), (new \DateTimeImmutable('2026-10-26 12:15:08', new \DateTimeZone('UTC')))->getTimestamp());

// The two DB-bound readers of changed_at must both go through the conversion (static: they need a database to run).
$repoSource = (string)file_get_contents($root . '/src/modules/booking/repositories/HospitalityOrderRepository.php');
$same('all three readers of changed_at convert it (getChangelog, getLastApplicantChanges, getApplicantChangesByApplication)', preg_match_all('/self::utcToVenueIso\(\$row\[\'changed_at\'\]\)/', $repoSource), 3);
$check('control: the pattern is specific (it does not match a raw changed_at assignment)', preg_match_all('/self::utcToVenueIso\(\$row\[\'changed_at\'\]\)/', "\$out[1] = \$row['changed_at'];") === 0);

// ======================================================================================================
// 5. The CONTROLLER's own path (pe-pm 34084 (b)): the real recordApplicantChange(), driven with the
//    repository and the notifier's outside-world seams stubbed. It proves that what the live write
//    path calls is the officer-locale render (singleton pre-set to `en`, as a citizen with
//    selected_lang=en leaves it), that the case entry is written BEFORE the mail, and that a mail
//    failure of any kind leaves the entry in place and does not throw.
// ======================================================================================================
class StubOrderRepo extends HospitalityOrderRepository
{
    public array $entries = [];
    public array $events = [];
    public ?\Throwable $entryThrows = null;

    public function __construct()
    {
    }

    public function addChangelogEntry(array $data): int
    {
        $this->events[] = 'entry';
        if ($this->entryThrows) {
            throw $this->entryThrows;
        }
        $this->entries[] = $data;
        return count($this->entries);
    }

    public function getEarliestApplicationDate(int $applicationId): ?string
    {
        return '2026-12-01 12:00:00';
    }
}

class OrderingNotifier extends TestNotifier
{
    public StubOrderRepo $repo;

    protected function deliver(string $to, string $subject, string $body): void
    {
        $this->repo->events[] = 'mail';
        parent::deliver($to, $subject, $body);
    }
}

class ThrowingNotifier extends CateringChangeNotifier
{
    public function notify(array $payload): bool
    {
        throw new \TypeError('a bug inside notify()');
    }
}

$controllerRef = new \ReflectionClass(HospitalityOrderController::class);
$record = $controllerRef->getMethod('recordApplicantChange');
$makeController = static function (StubOrderRepo $repo, CateringChangeNotifier $notifier) use ($controllerRef): object {
    $c = $controllerRef->newInstanceWithoutConstructor();
    $controllerRef->getProperty('orderRepo')->setValue($c, $repo);
    $controllerRef->getProperty('notifier')->setValue($c, $notifier);
    return $c;
};
$application = static fn(int $officer = 5): array => [
    'id' => 84091, 'status' => 'ACCEPTED', 'id_string' => '84091', 'name' => 'Sommerfest',
    'building_name' => 'Bygg A', 'customer_organization_name' => null, 'case_officer_id' => $officer,
];
$before = $order([$line('Kaffe', '3', '45')]);
$after = $order([$line('Kaffe', '4', '45')]);

// (b) the live path -> the officer-locale render, whatever the acting session's language is
$repo = new StubOrderRepo();
$fakeSwitch = new FakeLangSwitch('en');
$notifier = new OrderingNotifier(new CateringMailRenderer($fakeSwitch));
$notifier->repo = $repo;
$notifier->officer = ['email' => 'officer@example.test', 'lang' => null];
$record->invoke($makeController($repo, $notifier), 'changed', $application(), $before, $after, 77);
$same('controller path: a change writes ONE case entry', count($repo->entries), 1);
$same('controller path: the entry is «Endret av søker» (applicant_change)', $repo->entries[0]['change_type'] ?? null, 'applicant_change');
$same('controller path: the entry carries what changed from and to', [$repo->entries[0]['old_value']['line:Kaffe'] ?? null, $repo->entries[0]['new_value']['line:Kaffe'] ?? null], ['3 stk × 45.00 = 135.00', '4 stk × 45.00 = 180.00']);
$same('controller path: the entry belongs to the booking user', $repo->entries[0]['booking_user_id'] ?? null, 77);
$same('controller path: exactly one mail', count($notifier->delivered), 1);
$same('controller path: the entry is written BEFORE the mail', $repo->events, ['entry', 'mail']);
$same('controller path: acting session en, officer without preference -> the mail is Norwegian', $notifier->delivered[0][1] ?? null, 'Endret bevertning: Sommerfest, 10.11.2026');
$same('controller path: the acting language is put back', $fakeSwitch->current(), 'en');

// kinds
foreach ([
    ['added', null, $after, 'Ny bevertning: Sommerfest, 10.11.2026'],
    ['cancelled', $before, $order([$line('Kaffe', '3', '45')], ['status' => 'cancelled']), 'Avbestilt bevertning: Sommerfest, 10.11.2026'],
] as [$kind, $b, $a, $subject]) {
    $repo = new StubOrderRepo();
    $n = new OrderingNotifier(new CateringMailRenderer(new FakeLangSwitch('en')));
    $n->repo = $repo;
    $n->officer = ['email' => 'officer@example.test', 'lang' => null];
    $record->invoke($makeController($repo, $n), $kind, $application(), $b, $a, 77);
    $same("controller path ({$kind}): one entry and one mail", [count($repo->entries), count($n->delivered)], [1, 1]);
    $same("controller path ({$kind}): the subject", $n->delivered[0][1] ?? null, $subject);
}

// GUARD — «Sist endret» is intentionally NOT moved by a catering change (operator 2026-11-23, task 47036:
// «keep as is, changing it leads to issues»): no path a catering change takes writes bb_application. Static,
// because the writes need a database; the live before/after SELECT is in the SHA READY.
$appWrite = '/\b(?:UPDATE\s+bb_application|INSERT\s+INTO\s+bb_application|DELETE\s+FROM\s+bb_application)\b(?!_)/i';
foreach ([
    'the applicant path (bookingfrontend HospitalityOrderController)' => 'bookingfrontend/controllers/applications/HospitalityOrderController.php',
    'the officer path (booking HospitalityOrderController)' => 'booking/controllers/HospitalityOrderController.php',
    'the order repository both paths write through' => 'booking/repositories/HospitalityOrderRepository.php',
    'the notifier' => 'bookingfrontend/services/applications/CateringChangeNotifier.php',
] as $name => $file) {
    $src = is_file($root . '/src/modules/' . $file) ? (string)file_get_contents($root . '/src/modules/' . $file) : '';
    $check("guard: {$name} is there to read", $src !== '');
    $same("guard: {$name} writes no bb_application row («Sist endret» untouched)", preg_match_all($appWrite, $src), 0);
}
$check('guard control: the pattern finds a write of bb_application', preg_match($appWrite, "UPDATE bb_application SET modified = NOW() WHERE id = :id") === 1);
$check('guard control: ...and not one of bb_application_comment', preg_match($appWrite, "INSERT INTO bb_application_comment (application_id) VALUES (1)") === 0);

// a PUT that resends what the order already had: nothing to record, nobody to tell
$repo = new StubOrderRepo();
$n = new OrderingNotifier(new CateringMailRenderer(new FakeLangSwitch('no')));
$n->repo = $repo;
$record->invoke($makeController($repo, $n), 'changed', $application(), $before, $before, 77);
$same('controller path: an unchanged PUT leaves no entry and sends no mail', [count($repo->entries), count($n->delivered)], [0, 0]);

// no resolvable booking user (a hand-made ?secret= call): no entry (the table needs an author), still mailed
$repo = new StubOrderRepo();
$n = new OrderingNotifier(new CateringMailRenderer(new FakeLangSwitch('no')));
$n->repo = $repo;
$record->invoke($makeController($repo, $n), 'changed', $application(), $before, $after, null);
$same('controller path: no booking user -> no entry, but the officer is told', [count($repo->entries), count($n->delivered)], [0, 1]);

// no case officer: the entry is still written (ruling d), nothing is mailed
$repo = new StubOrderRepo();
$n = new OrderingNotifier(new CateringMailRenderer(new FakeLangSwitch('no')));
$n->repo = $repo;
$record->invoke($makeController($repo, $n), 'changed', $application(0), $before, $after, 77);
$same('controller path: no case officer, no building -> entry yes, mail no', [count($repo->entries), count($n->delivered)], [1, 0]);

// D1 through the controller: no case officer -> the controller hands the notifier the application's
// building, and the building's case officers are mailed. The entry is written as before.
$repo = new StubOrderRepo();
$n = new OrderingNotifier(new CateringMailRenderer(new FakeLangSwitch('en')));
$n->repo = $repo;
$n->groupIds = [3097, 3106];
$n->prefRows = [$pref(3097, ['email' => 'a@example.test', 'lang' => 'nn']), $pref(3106, ['email' => 'b@example.test'])];
$record->invoke($makeController($repo, $n), 'changed', $application(0) + ['building_id' => 10], $before, $after, 77);
$same('controller path D1: entry yes, and a mail to each of the building\'s case officers', [count($repo->entries), array_column($n->delivered, 0), $n->groupCalls], [1, ['a@example.test', 'b@example.test'], [10]]);
$same('controller path D1: each in their own language', array_column($n->delivered, 1), [$subjectIn('nn'), $subjectIn('no')]);
$repo = new StubOrderRepo();
$n = new OrderingNotifier(new CateringMailRenderer(new FakeLangSwitch('en')));
$n->repo = $repo;
$n->enabled = false;
$record->invoke($makeController($repo, $n), 'changed', $application(), $before, $after, 77);
$same('controller path D4 OFF: the case entry is still written, no mail', [count($repo->entries), count($n->delivered)], [1, 0]);

// S5 through the controller: the cancellation deadline in the mail is the guard's cancel verdict for the
// order — the value the applicant's own order list shows (actionsFor) — with the REAL guard and calculator.
// Serving 4 June 2029 15:00 venue time, cancellation 48 hours before, every day open -> 2 June 15:00.
class StubHospitalityRepo extends HospitalityRepository
{
    public function __construct()
    {
    }

    public function getById(int $id): ?array
    {
        return ['id' => $id, 'resource_cancellation_deadline_value' => 48, 'resource_cancellation_deadline_unit' => 'hours',
            'order_by_time_value' => null, 'order_by_time_unit' => null, 'open_days' => 127];
    }
}
class PaidNeverRepo extends StubOrderRepo
{
    public function isPaidAtCheckout(int $applicationId, int $hospitalityId): bool
    {
        return false;
    }
}
$s5Controller = static function (StubOrderRepo $repo, CateringChangeNotifier $n) use ($makeController, $controllerRef): object {
    $c = $makeController($repo, $n);
    $controllerRef->getProperty('guard')->setValue($c, new HospitalityOrderGuard());
    $controllerRef->getProperty('hospitalityRepo')->setValue($c, new StubHospitalityRepo());
    return $c;
};
$s5After = $order([$line('Kaffe', '4', '45')], ['hospitality_id' => 5, 'serving_time_iso' => '2029-06-04 13:00:00']);
$s5Before = $order([$line('Kaffe', '3', '45')], ['hospitality_id' => 5, 'serving_time_iso' => '2029-06-04 13:00:00']);
foreach (['changed' => $s5Before, 'added' => null] as $kind => $b) {
    $repo = new PaidNeverRepo();
    $n = new OrderingNotifier(new CateringMailRenderer(new FakeLangSwitch('en')));
    $n->repo = $repo;
    $n->officer = ['email' => 'officer@example.test', 'lang' => 'no'];
    $record->invoke($s5Controller($repo, $n), $kind, $application(), $b, $s5After, 77);
    $contains("controller path S5 ({$kind}): the officer's mail carries the order's cancellation deadline, in Norwegian", $n->delivered[0][2] ?? '', '<b>Avbestillingsfrist: 2. juni 2029 kl. 15:00</b>');
}
$repo = new PaidNeverRepo();
$n = new OrderingNotifier(new CateringMailRenderer(new FakeLangSwitch('en')));
$n->repo = $repo;
$n->officer = ['email' => 'officer@example.test', 'lang' => 'no'];
$record->invoke($s5Controller($repo, $n), 'cancelled', $application(), $s5Before, $order([$line('Kaffe', '3', '45')], ['hospitality_id' => 5, 'serving_time_iso' => '2029-06-04 13:00:00', 'status' => 'cancelled']), 77);
$check('controller path S5 (cancelled): one mail', count($n->delivered) === 1);
$lacks('controller path S5 (cancelled): ...without a deadline line', $n->delivered[0][2] ?? '', 'Avbestillingsfrist');

// FAILURE MUST NOT BREAK THE WRITE: the case entry stays, nothing throws
foreach ([
    'a TypeError inside notify()' => static fn(StubOrderRepo $r): CateringChangeNotifier => new ThrowingNotifier(),
    'a TypeError in the send' => static function (StubOrderRepo $r): CateringChangeNotifier {
        $n = new OrderingNotifier(new CateringMailRenderer(new FakeLangSwitch('en')));
        $n->repo = $r;
        $n->deliverThrows = new \TypeError('smtp');
        return $n;
    },
] as $name => $build) {
    $repo = new StubOrderRepo();
    $notifier = $build($repo);
    $threw = false;
    try {
        $record->invoke($makeController($repo, $notifier), 'changed', $application(), $before, $after, 77);
    } catch (\Throwable $e) {
        $threw = true;
    }
    $check("controller path: {$name}: does not throw", !$threw);
    $same("controller path: {$name}: the case entry is still there", count($repo->entries), 1);
}

// ...and the other way round: if writing the entry fails, the officer is still told
$repo = new StubOrderRepo();
$repo->entryThrows = new \TypeError('changelog');
$n = new OrderingNotifier(new CateringMailRenderer(new FakeLangSwitch('no')));
$n->repo = $repo;
$n->officer = ['email' => 'officer@example.test', 'lang' => 'no'];
$threw = false;
try {
    $record->invoke($makeController($repo, $n), 'changed', $application(), $before, $after, 77);
} catch (\Throwable $e) {
    $threw = true;
}
$check('controller path: a failing case entry does not throw', !$threw);
$same('controller path: ...and the officer is still told', count($n->delivered), 1);

// A Diff that fails with a PHP Error must not break the write either: the order is committed before
// recordApplicantChange() runs and the handlers catch Exception only, so a TypeError escaping here would
// turn a successful write into a 500. The failure is logged and the entry and the mail are skipped.
// A serving_time_iso that is not a string makes the real Diff throw a TypeError (the control).
$badAfter = $order([$line('Kaffe', '4', '45')], ['serving_time_iso' => ['not', 'a', 'string']]);
$diffError = null;
try {
    HospitalityOrderDiff::between($before, $badAfter);
} catch (\Throwable $e) {
    $diffError = $e;
}
$check('control: that order makes HospitalityOrderDiff::between throw a TypeError', $diffError instanceof \TypeError);
foreach (['added' => null, 'changed' => $before, 'cancelled' => $before] as $kind => $b) {
    $repo = new StubOrderRepo();
    $n = new OrderingNotifier(new CateringMailRenderer(new FakeLangSwitch('no')));
    $n->repo = $repo;
    $clearLog();
    $threw = false;
    try {
        $record->invoke($makeController($repo, $n), $kind, $application(), $b, $badAfter, 77);
    } catch (\Throwable $e) {
        $threw = true;
    }
    $check("controller path ({$kind}): a Diff that throws a TypeError does not throw", !$threw);
    $same("controller path ({$kind}): ...no entry and no mail", [count($repo->entries), count($n->delivered)], [0, 0]);
    $contains("controller path ({$kind}): ...and the failure is logged", $logTail(), 'Catering change diff failed for order 7');
}

// The default wiring of the notifier the controller builds is the OFFICER-locale renderer on the real
// Translation switch (not a raw render).
$default = new CateringChangeNotifier();
$rendererProp = (new \ReflectionClass($default))->getProperty('renderer')->getValue($default);
$langProp = (new \ReflectionClass($rendererProp))->getProperty('lang')->getValue($rendererProp);
$check('default wiring: the notifier renders through CateringMailRenderer', $rendererProp instanceof CateringMailRenderer);
$check('default wiring: ...on the real Translation switch', $langProp instanceof TranslationLangSwitch);

// And the controller reaches the mail ONLY through that notifier (static, with a control).
$controllerSource = (string)file_get_contents($root . '/src/modules/bookingfrontend/controllers/applications/HospitalityOrderController.php');
$same('the controller calls notifier->notify exactly once', preg_match_all('/\$this->notifier->notify\(/', $controllerSource), 1);
$same('the controller builds the default notifier', preg_match_all('/\$this->notifier\s*=\s*new CateringChangeNotifier\(\)/', $controllerSource), 1);
$same('the controller has no mail transport of its own', preg_match_all('/sendCateringChangeNotification|new EmailService|->msg\(|mail\(/', $controllerSource), 0);
$check('control: that pattern DOES match the notifier, which owns the transport',
    preg_match_all('/sendCateringChangeNotification|new EmailService/', (string)file_get_contents($root . '/src/modules/bookingfrontend/services/applications/CateringChangeNotifier.php')) > 0);

// The notification boundary catches Throwable, never Exception alone (the PHP 8 Error trap):
// CateringChangeNotifier is what stands between a mail failure and the committed catering write.
// (The renderer and the diff have narrow \Exception catches around DateTimeImmutable parsing that
// fall through to a fallback; an Error there is not swallowed, it reaches the notifier's catch.)
$notifierSource = (string)file_get_contents($root . '/src/modules/bookingfrontend/services/applications/CateringChangeNotifier.php');
$same('the notifier has no catch (Exception ...)', preg_match_all('/catch\s*\(\s*\\\\?Exception\b/', $notifierSource), 0);
$check('the notifier catches Throwable twice: around the whole notification and inside the deferred send', preg_match_all('/catch\s*\(\s*\\\\Throwable\b/', $notifierSource) >= 2);
$control = preg_match_all('/catch\s*\(\s*\\\\?Exception\b/', (string)file_get_contents($root . '/src/modules/bookingfrontend/controllers/applications/HospitalityOrderController.php'));
$check('control: the same pattern DOES find catch (Exception in the existing controller', $control > 0, "found {$control}");

// ======================================================================================================
// 6. S4 — one «Historikk» line per applicant change, READ from the case entries (task 47036)
// ======================================================================================================
$hasHistory = $hasText && method_exists(CateringChangeText::class, 'historyItems') && method_exists(CateringChangeText::class, 'mergeHistory');
$check('S4: the Historikk lines exist', $hasHistory);
if ($hasHistory) {
    $histRow = static fn(int $id, string $at, string $comment, ?array $old, ?array $new): array => [
        'id' => $id, 'order_id' => 122, 'changed_at' => $at, 'comment' => $comment, 'old_value' => $old, 'new_value' => $new,
        'booking_user_name' => 'Kari &amp; Ola', 'hospitality_name' => 'Fana kulturhus servering', 'location_name' => 'Anretning',
        'serving_time_iso' => '2029-06-04 13:00:00',
    ];
    $rows = [
        $histRow(13, '2026-11-18T12:10:00+01:00', 'Order cancelled by applicant via Min side', ['status' => 'pending'], ['status' => 'cancelled']),
        $histRow(12, '2026-11-18T12:00:00+01:00', 'Order changed by applicant via Min side', ['serving_time' => '04.06.2029 13:00'], ['serving_time' => '04.06.2029 15:00']),
        $histRow(11, '2026-11-18T11:38:00+01:00', 'Order added by applicant via Min side', null, ['hospitality' => 'Fana kulturhus servering', 'serving_time' => '04.06.2029 14:00', 'line:X' => '1 stk × 1.00 = 1.00']),
    ];
    $GLOBALS['__lang_now'] = 'no';
    $GLOBALS['__lang_missing'] = [];
    $items = CateringChangeText::historyItems($rows, 'no');
    $same('S4: one line per entry, worded per kind, the order and its serving time', array_column($items, 'comment'), [
        'Bevertning avbestilt — Fana kulturhus servering, 4. juni 2029 kl. 15:00. Endret av søker.',
        'Bevertning endret — Fana kulturhus servering, 4. juni 2029 kl. 15:00. Endret av søker.',
        'Bevertning lagt til — Fana kulturhus servering, 4. juni 2029 kl. 14:00. Endret av søker.',
    ]);
    $same('S4: shaped like the comments it is shown among, tagged «Bevertning»', [$items[0]['type'], $items[0]['type_label'], $items[0]['time'], $items[0]['changelog_id']], ['catering', 'Bevertning', '2026-11-18T12:10:00+01:00', 13]);
    $same('S4: the author is the applicant, decoded (escaped once by the page)', $items[0]['author'], 'Kari & Ola');
    $same('S4: no translation key missing (no)', $GLOBALS['__lang_missing'], []);
    $GLOBALS['__lang_now'] = 'en';
    $same('S4 en', CateringChangeText::historyItems([$rows[2]], 'en')[0]['comment'] ?? null, 'Catering added — Fana kulturhus servering, 4 June 2029 at 14:00. Changed by the applicant.');
    $GLOBALS['__lang_now'] = 'nn';
    $same('S4 nn', CateringChangeText::historyItems([$rows[1]], 'nn')[0]['comment'] ?? null, 'Bevertning endra — Fana kulturhus servering, 4. juni 2029 kl. 15:00. Endra av søkjar.');
    $GLOBALS['__lang_now'] = 'no';

    $comments = [
        ['id' => 2, 'time' => '2026-11-18T11:05:00+00:00', 'author' => 'henning', 'comment' => 'Svar', 'type' => 'comment'],
        ['id' => 1, 'time' => '2026-11-17T09:00:00+00:00', 'author' => 'Kari', 'comment' => 'Spørsmål', 'type' => 'comment'],
    ];
    $merged = CateringChangeText::mergeHistory($comments, $items);
    // c2 at 11:05 UTC is 12:05 in Oslo: between the 12:00 and the 12:10 entry, though its clock reads earlier.
    $same('S4: comments and catering lines in one list, newest first (instants, whatever the offset)',
        array_map(static fn($r) => $r['changelog_id'] ?? 'c' . $r['id'], $merged), [13, 'c2', 12, 11, 'c1']);
    $same('S4: no applicant entries -> the comments exactly as they were', CateringChangeText::mergeHistory($comments, []), $comments);
}
// The admin endpoint uses it, read-side only, and cannot be broken by it (static: it needs the app).
$appCtlSrc = (string)file_get_contents($root . '/src/modules/booking/controllers/ApplicationController.php');
$same('S4: showComments answers with the catering lines merged in', preg_match_all('/sendJSONResponse\(\$this->withCateringHistory\(\$id, \$comments\), 200, \$response\)/', $appCtlSrc), 1);
$check('S4: ...and anything going wrong there leaves the comments as they were (catch Throwable)',
    preg_match('/function withCateringHistory\(.*?catch \(\\\\Throwable \$e\) \{\s*error_log\("Catering history left out.*?return \$comments;/s', $appCtlSrc) === 1);
foreach (['booking/services/CateringChangeText.php', 'booking/repositories/HospitalityOrderRepository.php', 'bookingfrontend/controllers/applications/HospitalityOrderController.php'] as $file) {
    $src = is_file($root . '/src/modules/' . $file) ? (string)file_get_contents($root . '/src/modules/' . $file) : '';
    $same("S4: {$file} writes no bb_application_comment row (no leak to the applicant's page)", preg_match_all('/INSERT\s+INTO\s+bb_application_comment/i', $src), 0);
}
$check('S4 control: that pattern finds such a write', preg_match('/INSERT\s+INTO\s+bb_application_comment/i', "INSERT INTO bb_application_comment (application_id) VALUES (1)") === 1);
$showJs = is_file($root . '/src/modules/booking/html/base/application/show/application_show.js') ? (string)file_get_contents($root . '/src/modules/booking/html/base/application/show/application_show.js') : '';
$contains('S4: the Historikk shows the translated tag', $showJs, 'esc(comment.type_label || comment.type)');

// ======================================================================================================
// 7. D4 — the checkbox on the mail settings page: ticked when the key is absent (the real XSL)
// ======================================================================================================
$xslPath = $root . '/src/modules/booking/templates/base/mail_settings.xsl';
$xslSrc = is_file($xslPath) ? (string)file_get_contents($xslPath) : '';
$hasBox = str_contains($xslSrc, 'notify_case_officer_on_catering_change');
$check('D4: the mail settings page has the switch', $hasBox);
if ($hasBox && class_exists(\XSLTProcessor::class)) {
    $wrapped = '<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform" xmlns:php="http://php.net/xsl">'
        . '<xsl:output method="html"/><xsl:template name="msgbox"/>' . $xslSrc . '</xsl:stylesheet>';
    $sheet = new \DOMDocument();
    $sheet->loadXML($wrapped);
    $proc = new \XSLTProcessor();
    $proc->registerPHPFunctions();
    $proc->importStylesheet($sheet);
    $GLOBALS['__lang_now'] = 'no';
    $box = static function (?string $stored) use ($proc): array {
        $xml = new \DOMDocument();
        $xml->loadXML('<data><config_data>' . ($stored === null ? '' : '<notify_case_officer_on_catering_change>' . $stored . '</notify_case_officer_on_catering_change>')
            . '</config_data><data><tabs/></data></data>');
        $html = (string)$proc->transformToXml($xml);
        $input = preg_match('/<input[^>]*id="field_notify_case_officer_on_catering_change"[^>]*>/', $html, $m) ? $m[0] : '';
        return [$input !== '', str_contains($input, 'checked')];
    };
    $same('D4 page: key absent -> the box is TICKED (absent = ON, as shipped)', $box(null), [true, true]);
    $same('D4 page: stored "1" -> ticked', $box('1'), [true, true]);
    $same('D4 page: stored "0" -> not ticked', $box('0'), [true, false]);
    $html = (string)$proc->transformToXml((static function () { $d = new \DOMDocument(); $d->loadXML('<data><config_data/><data><tabs/></data></data>'); return $d; })());
    $check('D4 page: an unticked box still posts "0" (hidden input first, the checkbox "1" after it wins when ticked)',
        preg_match('/<input name="notify_case_officer_on_catering_change" type="hidden" value="0">\s*<input id="field_notify_case_officer_on_catering_change"[^>]*name="notify_case_officer_on_catering_change"[^>]*value="1"/', $html) === 1, substr($html, (int)strpos($html, 'notify_case_officer'), 400));
    $contains('D4 page: the label is translated', $html, 'Send e-post til saksbehandler når søker endrer bevertning');
    $check('D4 page control: the existing notify-on-accepted box, absent, is NOT ticked (the probe can see an unticked box)',
        preg_match('/<input[^>]*id="field_application_notify_on_accepted"[^>]*>/', $html, $m) === 1 && !str_contains($m[0], 'checked'));
} elseif ($hasBox) {
    $check('D4 page: the xsl extension is needed to render the page', false);
}

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
