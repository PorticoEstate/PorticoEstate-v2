<?php

/**
 * MUTANTS of the legacy twins (task #48299): put the Preferences singleton back at ONE of the four sites, run
 * probe_legacy.php on it, and require that THAT SITE'S OWN ARMS go RED — and exactly those.
 *
 *   docker exec pe-api php /tmp/t48299/<name>/tests/preferences-reader/mutate_legacy.php FIX_EXPORT [BASE_EXPORT]
 *
 * Each mutant is a directory holding the fix export's two files (booking/inc/class.boapplication.inc.php and
 * registration/inc/class.soreg.inc.php) in which exactly ONE `PreferencesReader::read(…)` is replaced by the
 * `CreateObject('phpgwapi.preferences', $id)` it replaced. The text to replace is asserted to occur exactly once and
 * the result is lint-checked, so a mutant that silently did not apply can never be mistaken for a GREEN one.
 *
 * EXPECTED, from the code paths (probe_legacy.php's header). An arm is RED under a mutant iff the mutated line is on
 * that arm's path:
 *
 *   mutant  site                                              RED arms
 *   M189    boapplication::sendCaseOfficerNotifications :189  A1 A2 A8
 *   M247    boapplication::sendSmsNotifications :247          A1 A3 A8
 *   M350    boapplication::get_mail_addresses :350            A1 A2 A3 A4 A5 A6 A8   (every path through get_mail_addresses)
 *   Msoreg  registration_soreg::lostpw1 :271                  A7
 *
 * Controls: the UNMUTATED fix has no RED arm and exits 0; the BASE export (second argument) has all eight RED.
 * A mutant RED on an arm that is not in its row, or GREEN on one that is, is a FINDING, printed as FAIL.
 * Each mutant's source guard must also fail, for the file it mutates and only that one.
 *
 * Exit code: 0 as expected everywhere, 1 any difference.
 */

$fix = $argv[1] ?? '';
$base = $argv[2] ?? null;
if (!is_dir("$fix/src") || !is_file("$fix/tests/preferences-reader/probe_legacy.php")) {
    fwrite(STDERR, "usage: mutate_legacy.php FIX_EXPORT [BASE_EXPORT]\n");
    exit(2);
}
set_time_limit(0);

const ARMS = ['A1', 'A2', 'A3', 'A4', 'A5', 'A6', 'A7', 'A8'];
const BO = 'src/modules/booking/inc/class.boapplication.inc.php';
const SOREG = 'src/modules/registration/inc/class.soreg.inc.php';
const FILES = [BO, SOREG];

$bit = static fn(int $b): string => "\n\t\t\tif (isset(\$prefs['booking']['notify_on_new']) && (\$prefs['booking']['notify_on_new'] & $b))";

/** name => [file, fix text, singleton text, expected RED arms, source-guard class that must fail] */
$mutants = [
    'M189' => [BO,
        "\t\t\t\$prefs = PreferencesReader::read((int)\$user_id, ['booking', 'common']);" . $bit(1),
        "\t\t\t\$prefs = CreateObject('phpgwapi.preferences', \$user_id)->read();" . $bit(1),
        ['A1', 'A2', 'A8'], 'booking_boapplication'],
    'M247' => [BO,
        "\t\t\t\$prefs = PreferencesReader::read((int)\$user_id, ['booking', 'common']);" . $bit(2),
        "\t\t\t\$prefs = CreateObject('phpgwapi.preferences', \$user_id)->read();" . $bit(2),
        ['A1', 'A3', 'A8'], 'booking_boapplication'],
    'M350' => [BO,
        "\t\t\t\$prefs = PreferencesReader::read((int)\$user, ['common']);",
        "\t\t\t\$prefs = CreateObject('phpgwapi.preferences', \$user)->read();",
        ['A1', 'A2', 'A3', 'A4', 'A5', 'A6', 'A8'], 'booking_boapplication'],
    'Msoreg' => [SOREG,
        "\t\t\t\$preferences = PreferencesReader::read((int)\$user_id, ['common']);\n\t\t\t\$info['email'] = isset(\$preferences['common']['email']) && \$preferences['common']['email'] ? \$preferences['common']['email'] : '';",
        "\t\t\t\$preferences = createObject('phpgwapi.preferences', \$user_id);\n\t\t\t\$info['email'] = isset(\$preferences->data['common']['email']) && \$preferences->data['common']['email'] ? \$preferences->data['common']['email'] : '';",
        ['A7'], 'registration_soreg'],
];

$work = sys_get_temp_dir() . '/t48299-mut';
if (is_dir($work)) {
    exec('rm -rf ' . escapeshellarg($work));   // this script's own scratch directory, nothing else
}
mkdir($work, 0777, true);

$pass = 0;
$fail = 0;
$ok = function (string $name, bool $cond, string $detail = '') use (&$pass, &$fail): void {
    $cond ? $pass++ : $fail++;
    echo ($cond ? '  ok   ' : '  FAIL ') . $name . ($cond || $detail === '' ? '' : "  — $detail") . "\n";
};

$probe = "$fix/tests/preferences-reader/probe_legacy.php";

/** Run the probe on an export; return [exit code, [arm => identity_equal] or null, failing source-guard classes]. */
$runProbe = function (string $export, string $tag) use ($probe, $work): array {
    $out = "$work/$tag.json";
    @unlink($out);
    $lines = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' ' . escapeshellarg($export) . ' --out=' . escapeshellarg($out) . ' 2>&1', $lines, $code);
    $guards = [];
    foreach ($lines as $l) {
        if (preg_match('/^\s*FAIL (booking_boapplication|registration_soreg) creates no Preferences singleton/', $l, $m)) {
            $guards[] = $m[1];
        }
    }
    if (!is_file($out)) {
        echo '    probe did not write results (exit ' . $code . '): ' . implode(' | ', array_slice(array_filter($lines, static fn($l) => !str_contains($l, 'Xdebug')), -3)) . "\n";
        return [$code, null, $guards];
    }
    $json = json_decode((string)file_get_contents($out), true);
    return [$code, array_map(static fn(array $r): bool => $r['identity_equal'], $json), $guards];
};
$redArms = static fn(array $identity): array => array_values(array_filter(ARMS, static fn(string $a): bool => isset($identity[$a]) && $identity[$a] === false));

echo 'arms: ' . implode(' ', ARMS) . "\n";

// ---- control 1: the UNMUTATED fix -----------------------------------------------------------------------------------
echo "control: the fix export, unmutated\n";
[$code, $id, $guards] = $runProbe($fix, 'fix');
$ok('the unmutated fix: NO arm is RED, all eight ran', $id !== null && $redArms($id) === [] && count($id) === count(ARMS), json_encode($id === null ? null : $redArms($id)));
$ok('the unmutated fix: the probe exits 0 and no source guard fails', $code === 0 && $guards === [], "exit $code, guards " . json_encode($guards));

// ---- control 2: the base export ---------------------------------------------------------------------------------------
if ($base !== null) {
    echo "control: the base export, all four sites on the singleton\n";
    [$code, $id, $guards] = $runProbe($base, 'base');
    $ok('the base: ALL eight arms are RED', $id !== null && $redArms($id) === ARMS, json_encode($id === null ? null : $redArms($id)));
    $ok('the base: the probe exits 1 and both source guards fail', $code === 1 && $guards === ['booking_boapplication', 'registration_soreg'], "exit $code, guards " . json_encode($guards));
}

// ---- the mutants -----------------------------------------------------------------------------------------------------------
$matrix = [];
foreach ($mutants as $name => [$file, $fixText, $singletonText, $expected, $guardClass]) {
    echo "mutant $name: $file\n";
    $dir = "$work/$name";
    foreach (FILES as $f) {
        @mkdir(dirname("$dir/$f"), 0777, true);
        copy("$fix/$f", "$dir/$f");
    }
    $src = (string)file_get_contents("$dir/$file");
    $count = substr_count($src, $fixText);
    $ok("$name: the text to put back occurs exactly once in $file", $count === 1, "found $count");
    if ($count !== 1) {
        continue;
    }
    file_put_contents("$dir/$file", str_replace($fixText, $singletonText, $src));
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg("$dir/$file") . ' 2>&1', $lint, $lintCode);
    $ok("$name: the mutant lints", $lintCode === 0);
    $mutated = (string)file_get_contents("$dir/$file");
    $ok("$name: the mutant really has the singleton call and no longer the reader call at that site", str_contains($mutated, $singletonText) && !str_contains($mutated, $fixText));

    [$code, $identity, $guards] = $runProbe($dir, $name);
    $red = $identity === null ? null : $redArms($identity);
    $matrix[$name] = $identity;
    $ok("$name: RED on exactly its arms [" . implode(' ', $expected) . ']', $red === $expected, 'RED on ' . json_encode($red));
    $ok("$name: the source guard fails for $guardClass and for nothing else", $guards === [$guardClass], 'failing guards: ' . json_encode($guards));
}

// ---- the matrix, for the record ------------------------------------------------------------------------------------------------
echo "\nmatrix (RED = the identity flipped in that arm)\n";
echo sprintf('  %-8s', '') . implode('', array_map(static fn(string $a): string => sprintf('%-6s', $a), ARMS)) . "\n";
foreach ($matrix as $name => $identity) {
    echo sprintf('  %-8s', $name);
    foreach (ARMS as $a) {
        echo sprintf('%-6s', $identity === null ? '?' : (($identity[$a] ?? null) === false ? 'RED' : 'ok'));
    }
    echo "\n";
}
echo 'MUTATE: ' . ($fail ? "FAIL $fail of " . ($pass + $fail) : "PASS $pass") . "\n";
exit($fail ? 1 : 0);
