<?php

/**
 * MUTANTS (task #47037): put the Preferences singleton back at ONE site of the fix, run the request-level
 * identity probe on it, and require that THAT SITE'S OWN ARM goes RED — and exactly the arms that reach it.
 *
 *   docker exec pe-api php /tmp/t47037/<name>/tests/preferences-reader/mutate.php FIX_EXPORT [BASE_EXPORT]
 *
 * Each mutant is a copy of the fix export's four files in which exactly ONE `PreferencesReader::read(…)`
 * is replaced by the `CreateObject('phpgwapi.preferences', $id)->read()` it replaced. The replacement is
 * asserted to match exactly once and the result is lint-checked, so a mutant that silently did not apply
 * can never be mistaken for a GREEN one. Each mutant then runs:
 *   - probe_identity.php (real database, nine request-level arms): which arms flip the identity?
 *   - run_reader.php (hermetic): does the source guard / the behaviour tests notice?
 *
 * EXPECTED, from the code paths (probe_identity.php's header): an arm is RED under a mutant iff the mutated
 * line is on that arm's path.
 *
 *   mutant   site                                            RED arms
 *   M785     EmailService::getMailAddresses :785             T G1 G2 S S-off S-sms   (every path through getMailAddresses)
 *   M425     EmailService::sendCaseOfficerNotifications      S S-off
 *   M711     EmailService::sendSmsNotifications              S S-sms
 *   M96      CateringChangeNotifier::loadOfficer             K-added K-changed K-cancelled
 *   MAT      AllocationTimeChangeNotifier::loadOfficer       T
 *
 * Controls: the UNMUTATED fix has no RED arm; the BASE export (given as the second argument) has all nine.
 * A mutant RED on an arm that is not in its row, or GREEN on one that is, is a FINDING, printed as FAIL.
 *
 * Exit code: 0 as expected everywhere, 1 any difference.
 */

$fix = $argv[1] ?? '';
$base = $argv[2] ?? null;
if (!is_dir("$fix/src") || !is_file("$fix/tests/preferences-reader/probe_identity.php")) {
    fwrite(STDERR, "usage: mutate.php FIX_EXPORT [BASE_EXPORT]\n");
    exit(2);
}
set_time_limit(0);

const ARMS = ['T', 'G1', 'G2', 'S', 'S-off', 'S-sms', 'K-added', 'K-changed', 'K-cancelled'];
const FILES = [
    'src/modules/phpgwapi/services/PreferencesReader.php',
    'src/modules/booking/services/EmailService.php',
    'src/modules/bookingfrontend/services/AllocationTimeChangeNotifier.php',
    'src/modules/bookingfrontend/services/applications/CateringChangeNotifier.php',
];
const EMAIL = 'src/modules/booking/services/EmailService.php';
const AT = 'src/modules/bookingfrontend/services/AllocationTimeChangeNotifier.php';
const CATERING = 'src/modules/bookingfrontend/services/applications/CateringChangeNotifier.php';

/** name => [file, fix text, singleton text, expected RED arms] */
$mutants = [
    'M785' => [EMAIL, "PreferencesReader::read((int)\$user, ['common']);", "CreateObject('phpgwapi.preferences', \$user)->read();", ['T', 'G1', 'G2', 'S', 'S-off', 'S-sms']],
    'M425' => [EMAIL, "PreferencesReader::read((int)\$user_id, ['booking']);", "CreateObject('phpgwapi.preferences', \$user_id)->read();", ['S', 'S-off']],
    'M711' => [EMAIL, "PreferencesReader::read((int)\$user_id, ['booking', 'common']);", "CreateObject('phpgwapi.preferences', \$user_id)->read();", ['S', 'S-sms']],
    'M96' => [CATERING, "PreferencesReader::read(\$accountId, ['common'])['common'] ?? [];", "CreateObject('phpgwapi.preferences', \$accountId)->read()['common'] ?? [];", ['K-added', 'K-changed', 'K-cancelled']],
    'MAT' => [AT, "PreferencesReader::read(\$accountId, ['common'])['common'] ?? [];", "CreateObject('phpgwapi.preferences', \$accountId)->read()['common'] ?? [];", ['T']],
];

$work = sys_get_temp_dir() . '/t47037-mut';
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

$probe = "$fix/tests/preferences-reader/probe_identity.php";
$reader = "$fix/tests/preferences-reader/run_reader.php";

/** Run the identity probe on an export; return [armName => identity_equal] or null when it did not run. */
$runProbe = function (string $export, string $tag) use ($probe, $work): ?array {
    $out = "$work/$tag.json";
    @unlink($out);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' ' . escapeshellarg($export) . ' --out=' . escapeshellarg($out) . ' 2>&1';
    exec($cmd, $lines, $code);
    if (!is_file($out)) {
        echo "    probe did not write results (exit $code): " . implode(' | ', array_slice(array_filter($lines, static fn($l) => !str_contains($l, 'Xdebug')), -3)) . "\n";
        return null;
    }
    $json = json_decode((string)file_get_contents($out), true);
    return array_map(static fn(array $r): bool => $r['identity_equal'], $json);
};
$redArms = static fn(array $identity): array => array_values(array_filter(ARMS, static fn(string $a): bool => isset($identity[$a]) && $identity[$a] === false));

/** Run the hermetic reader runner against an export's four files. Returns [exit code, failure-line count, first failure]. */
$runReader = function (string $export) use ($reader): array {
    $cmd = 'HD_ROOT=' . escapeshellarg($export) . ' PHPGW_SERVER_ROOT=/var/www/html/src/modules VENDOR_AUTOLOAD=/var/www/html/vendor/autoload.php '
        . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($reader) . ' 2>&1';
    exec($cmd, $lines, $code);
    $failures = array_values(array_filter($lines, static fn($l) => str_starts_with(trim($l), 'FAIL ')));
    return [$code, count($failures), isset($failures[0]) ? substr(trim($failures[0]), 0, 150) : ''];
};

echo 'arms: ' . implode(' ', ARMS) . "\n";

// ---- control 1: the UNMUTATED fix --------------------------------------------------------------------------------
echo "control: the fix export, unmutated\n";
$id = $runProbe($fix, 'fix');
$ok('the unmutated fix: NO arm is RED', $id !== null && $redArms($id) === [] && count($id) === count(ARMS), json_encode($id === null ? null : $redArms($id)));
[$code, $nFail] = $runReader($fix);
$ok('the unmutated fix: the hermetic reader runner PASSES', $code === 0 && $nFail === 0, "exit $code, $nFail failing checks");

// ---- control 2: the base export ---------------------------------------------------------------------------------------
if ($base !== null) {
    echo "control: the base export (79a6524b1), all five sites on the singleton\n";
    $id = $runProbe($base, 'base');
    $ok('the base: ALL nine arms are RED', $id !== null && $redArms($id) === ARMS, json_encode($id === null ? null : $redArms($id)));
}

// ---- the mutants --------------------------------------------------------------------------------------------------------
$matrix = [];
foreach ($mutants as $name => [$file, $fixText, $singletonText, $expected]) {
    echo "mutant $name: $file\n  $fixText  →  $singletonText\n";
    $dir = "$work/$name";
    foreach (FILES as $f) {
        @mkdir(dirname("$dir/$f"), 0777, true);
        copy("$fix/$f", "$dir/$f");
    }
    $src = (string)file_get_contents("$dir/$file");
    $count = substr_count($src, $fixText);
    $ok("$name: the line to put back occurs exactly once in $file", $count === 1, "found $count");
    if ($count !== 1) {
        continue;
    }
    file_put_contents("$dir/$file", str_replace($fixText, $singletonText, $src));
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg("$dir/$file") . ' 2>&1', $lint, $lintCode);
    $ok("$name: the mutant lints", $lintCode === 0);
    $ok("$name: the mutant really calls the singleton and no longer the reader at that line", str_contains((string)file_get_contents("$dir/$file"), $singletonText) && !str_contains((string)file_get_contents("$dir/$file"), $fixText));

    $identity = $runProbe($dir, $name);
    $red = $identity === null ? null : $redArms($identity);
    $matrix[$name] = $identity;
    $ok("$name: RED on exactly its arms [" . implode(' ', $expected) . ']', $red === $expected, 'RED on ' . json_encode($red));
    [$code, $nFail, $first] = $runReader($dir);
    $ok("$name: the hermetic reader runner goes RED too", $code !== 0 && $nFail >= 1, "exit $code, $nFail failing checks");
    echo "    hermetic: $nFail failing check(s)" . ($first !== '' ? "; first: $first" : '') . "\n";
}

// ---- the matrix, for the record -------------------------------------------------------------------------------------------
echo "\nmatrix (RED = the identity flipped in that arm)\n";
echo sprintf("  %-6s", '') . implode('', array_map(static fn(string $a): string => sprintf('%-12s', $a), ARMS)) . "\n";
foreach ($matrix as $name => $identity) {
    echo sprintf('  %-6s', $name);
    foreach (ARMS as $a) {
        echo sprintf('%-12s', $identity === null ? '?' : (($identity[$a] ?? null) === false ? 'RED' : 'ok'));
    }
    echo "\n";
}
echo 'MUTATE: ' . ($fail ? "FAIL $fail of " . ($pass + $fail) : "PASS $pass") . "\n";
exit($fail ? 1 : 0);
