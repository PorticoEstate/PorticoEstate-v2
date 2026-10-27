<?php

/**
 * Hermetic tests for the hospitality timestamps (GH #1423, #1425): every hospitality time is STORED
 * as naive UTC and READ as UTC, whatever zone the database session runs in.
 *
 *   HD_PG_DSN='pgsql:host=127.0.0.1;port=5435;dbname=postgres' HD_PG_USER=postgres HD_PG_PASS=... \
 *       php tests/hospitality-deadline/run_tz.php
 *
 * Unlike run_mail.php this needs a real Postgres, because the whole point is what the DATABASE does
 * with its session zone. It runs the REAL repositories, models, controller helper and migration:
 *
 *   T1  writers      every writer, in a UTC and an Oslo database, stores now() as UTC
 *   T2  readers      stored UTC reads back as the right instant (winter, summer, both passes of the
 *                    fall-back hour, spring-forward) as an offset-bearing ISO string, every reader path
 *   T3  migration    rows converted from the session zone to UTC (Oslo: -1 h winter, -2 h summer;
 *                    UTC: untouched), defaults switched, a second run changes nothing, and it REFUSES
 *                    (changing nothing) on a client/session zone, on half-done or hand-edited defaults
 *   T4  deploy order new code before the migration, the migration, new code after: all correct; old
 *                    code's explicit NOW() after it: the documented residual
 *   T5  conflict     X-If-Modified-Since against the stored modified, under three PHP default zones
 *
 * Isolation (pe-pm 34261 C3): it creates two SCRATCH DATABASES (hd_tz_utc_*, hd_tz_oslo_*; the second
 * with ALTER DATABASE ... SET timezone, which is the only way to give a session a zone that comes from
 * the SERVER without touching anyone's configuration), runs each arm as a child process connected to
 * one of them, and drops both at the end. The arms create their own tables there, so no table of the
 * application is read or written and a leak would ERROR instead of reaching real rows. The DSN user
 * needs CREATEDB; without it, or without HD_PG_DSN, the run prints SKIPPED and exits 3 - never green.
 *
 * The arms DROP TABLE ... CASCADE on tables the application has too (phpgw_accounts, bb_user, bb_resource,
 * ...), so an arm started by hand against the wrong database would wipe real data. Each arm therefore
 * refuses, before it creates or drops anything, unless it is connected to a database that is (1) named
 * like a scratch database the master makes, (2) the very one named in HD_ARM_DB, which only the master
 * sets, and (3) holds no table this harness did not create. It then prints why on stderr and exits 2.
 * The master tests that refusal end to end against decoy databases, with a canary that must survive.
 *
 * Environment: HD_PG_DSN, HD_PG_USER, HD_PG_PASS (required); HD_ROOT = tree holding src/ (default:
 * this repo; mutate_tz.py points it at a mutated copy). Nothing here is read from the application's
 * own configuration and no credential is stored in the repo. HD_ARM and HD_ARM_DB belong to the master.
 *
 * Exit code: 0 all pass, 1 any failure, 2 bad environment (an arm: refused), 3 skipped.
 */

const HD_UTC_DEFAULT = "(now() AT TIME ZONE 'UTC'::text)";

/** label => the real instant. The label says what the database has to get right. */
const HD_CASES = [
    'winter 2026-10-27 08:30:39Z' => '2026-10-27 08:30:39.335896+00',
    'summer 2026-07-01 10:00:00Z' => '2026-07-01 10:00:00+00',
    'fall-back 1st pass 2026-10-25 00:30Z (02:30 CEST)' => '2026-10-25 00:30:00+00',
    'fall-back 2nd pass 2026-10-25 01:30Z (02:30 CET)' => '2026-10-25 01:30:00+00',
    'spring-forward before 2026-03-29 00:30Z' => '2026-03-29 00:30:00+00',
    'spring-forward after 2026-03-29 01:30Z' => '2026-03-29 01:30:00+00',
];

/** table => the columns the database fills from its default (serving_time_iso is explicit UTC and not here). */
const HD_COLUMNS = [
    'bb_hospitality' => ['created', 'modified'],
    'bb_hospitality_order' => ['created', 'modified'],
    'bb_hospitality_order_changelog' => ['changed_at'],
];

/** What the master calls a scratch database: hd_tz_<utc|oslo>_<its pid>_<6 hex>. An arm runs in nothing else. */
const HD_SCRATCH_DB = '/^hd_tz_(utc|oslo)_[0-9]+_[0-9a-f]{6}$/D';

/** Every table an arm creates, in the order it DROPs them first. A scratch database holds these and nothing else. */
const HD_ARM_TABLES = [
    'bb_hospitality_order_changelog', 'bb_hospitality_order_line', 'bb_hospitality_order', 'bb_hospitality', 'bb_hospitality_article',
    'bb_article_mapping', 'bb_article_view', 'bb_resource', 'phpgw_accounts', 'bb_user',
];

/** How many checks each arm prints. A check that vanishes silently shows up as a shortfall instead of as a pass. */
const HD_ARM_CHECKS = ['utc-db' => 136, 'oslo-db' => 140, 'client-zone' => 6];

$root = getenv('HD_ROOT') ?: dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'App\\')) {
        $file = $root . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

use App\Database\Db;
use App\modules\booking\controllers\HospitalityController;
use App\modules\booking\models\Hospitality;
use App\modules\booking\models\HospitalityOrder;
use App\modules\booking\repositories\HospitalityOrderRepository;
use App\modules\booking\repositories\HospitalityRepository;

$arm = getenv('HD_ARM') ?: '';
exit($arm === '' ? hd_master($root) : hd_arm($arm, $root));

// ======================================================================================================
// the master: environment, scratch databases, children, tally
// ======================================================================================================
function hd_master(string $root): int
{
    $dsn = getenv('HD_PG_DSN');
    $user = getenv('HD_PG_USER');
    $pass = getenv('HD_PG_PASS');
    if (!$dsn || $user === false) {
        echo "TZ harness: SKIPPED (set HD_PG_DSN, HD_PG_USER, HD_PG_PASS: it needs a real Postgres with CREATEDB)\n";
        return 3;
    }

    $checks = [];
    $record = static function (string $name, bool $ok, string $detail = '') use (&$checks): void {
        $checks[] = [$name, $ok, $detail];
    };

    // --- static: no writer is left on an explicit NOW() (control: the pattern does match one) -----
    $repoSources = [
        'HospitalityRepository' => (string)@file_get_contents($root . '/src/modules/booking/repositories/HospitalityRepository.php'),
        'HospitalityOrderRepository' => (string)@file_get_contents($root . '/src/modules/booking/repositories/HospitalityOrderRepository.php'),
    ];
    $now = '/modified\s*=\s*NOW\(\)/i';
    $record('control: the NOW() pattern matches an explicit NOW() writer', preg_match($now, 'SET modified = NOW(), x') === 1);
    $record('control: ...and does not match a DEFAULT writer', preg_match($now, 'SET modified = DEFAULT, x') === 0);
    foreach ($repoSources as $name => $source) {
        $record("{$name} has no writer left on an explicit NOW()", $source !== '' && preg_match_all($now, $source) === 0, 'found ' . preg_match_all($now, $source) . ' (or the file is unreadable)');
    }
    $record('the four former NOW() writers say modified = DEFAULT', preg_match_all('/modified\s*=\s*DEFAULT/i', implode("\n", $repoSources)) === 4, 'found ' . preg_match_all('/modified\s*=\s*DEFAULT/i', implode("\n", $repoSources)));

    // --- T5: the conflict check, no database -------------------------------------------------
    foreach (hd_conflict_checks() as [$name, $ok, $detail]) {
        $record($name, $ok, $detail);
    }

    // --- the arm guard's name pattern, no database --------------------------------------------
    foreach (hd_scratch_name_checks() as [$name, $ok, $detail]) {
        $record($name, $ok, $detail);
    }

    // --- scratch databases ---------------------------------------------------------------------
    $suffix = getmypid() . '_' . bin2hex(random_bytes(3));
    $dbUtc = "hd_tz_utc_{$suffix}";
    $dbOslo = "hd_tz_oslo_{$suffix}";
    // for the arm guard's own tests: a database whose name no arm may run in, and one with a name it would
    // accept but that the master never hands to an arm
    $dbDecoy = "hd_decoy_{$suffix}";
    $dbSpare = 'hd_tz_utc_' . getmypid() . '_' . bin2hex(random_bytes(3));
    try {
        $admin = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (Throwable $e) {
        echo 'TZ harness: SKIPPED (cannot connect: ' . $e->getMessage() . ")\n";
        return 3;
    }
    $created = [];
    try {
        foreach ([$dbUtc, $dbOslo, $dbDecoy, $dbSpare] as $name) {
            $admin->exec("CREATE DATABASE \"{$name}\" TEMPLATE template0");
            $created[] = $name;
        }
        $admin->exec("ALTER DATABASE \"{$dbOslo}\" SET timezone TO 'Europe/Oslo'");
    } catch (Throwable $e) {
        foreach ($created as $name) {
            $admin->exec("DROP DATABASE IF EXISTS \"{$name}\" WITH (FORCE)");
        }
        echo 'TZ harness: SKIPPED (cannot create scratch databases - the user needs CREATEDB: ' . $e->getMessage() . ")\n";
        return 3;
    }

    $dsnFor = static fn(string $db): string => preg_match('/dbname=[^;]*/', $dsn) ? preg_replace('/dbname=[^;]*/', 'dbname=' . $db, $dsn) : $dsn . ';dbname=' . $db;
    $arms = [
        'utc-db' => ['db' => $dbUtc, 'env' => []],
        'oslo-db' => ['db' => $dbOslo, 'env' => []],
        // the zone arrives from the CLIENT (libpq PGOPTIONS), which the app's own sessions would not share
        'client-zone' => ['db' => $dbUtc, 'env' => ['PGOPTIONS' => '-c timezone=Europe/Oslo']],
    ];
    $armsRun = [];
    try {
        foreach ($arms as $armName => $spec) {
            $env = array_merge(getenv(), $spec['env'], [
                'HD_ARM' => $armName,
                'HD_ARM_DB' => $spec['db'],
                'HD_ROOT' => $root,
                'HD_PG_DSN' => $dsnFor($spec['db']),
                'HD_PG_USER' => (string)$user,
                'HD_PG_PASS' => (string)$pass,
            ]);
            [$rc, $out, $err] = hd_spawn_arm($env);
            $rows = hd_arm_rows($out);
            foreach ($rows as $row) {
                $record("[{$armName}] " . $row['n'], (bool)$row['ok'], (string)($row['d'] ?? ''));
            }
            $n = count($rows);
            $armsRun[] = "{$armName}={$n}";
            if ($rc !== 0 || $n === 0) {
                $record("[{$armName}] the arm ran to the end (exit {$rc}, {$n} checks)", false, hd_stderr_tail($err));
            }
            $record("[{$armName}] the arm printed all " . HD_ARM_CHECKS[$armName] . ' of its checks', $n === HD_ARM_CHECKS[$armName], "printed {$n}");
        }

        foreach (hd_guard_checks($dsnFor, (string)$user, (string)$pass, $root, ['decoy' => $dbDecoy, 'spare' => $dbSpare, 'real' => $dbUtc], $arms['client-zone']['env']) as [$name, $ok, $detail]) {
            $record($name, $ok, $detail);
        }
    } finally {
        foreach ($created as $name) {
            $admin->exec("DROP DATABASE IF EXISTS \"{$name}\" WITH (FORCE)");
        }
    }

    $failures = array_filter($checks, static fn($c) => !$c[1]);
    if ($failures) {
        foreach ($failures as [$name, , $detail]) {
            echo "FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
        }
        echo 'TZ harness: FAIL ' . count($failures) . ' of ' . count($checks) . ' checks on PHP ' . PHP_VERSION . "\n";
        return 1;
    }
    echo 'TZ harness: PASS ' . count($checks) . ' checks on PHP ' . PHP_VERSION . ' (arms: ' . implode(', ', $armsRun) . ")\n";
    return 0;
}

/** T5, plain PHP: HospitalityController::isStaleVersion under different PHP default zones. */
function hd_conflict_checks(): array
{
    $out = [];
    $add = static function (string $name, bool $ok, string $detail = '') use (&$out): void {
        $out[] = [$name, $ok, $detail];
    };
    if (!method_exists(HospitalityController::class, 'isStaleVersion')) {
        return [['HospitalityController::isStaleVersion exists', false, 'the conflict check is still inline in update()']];
    }
    $stored = '2026-10-27 08:30:39.335896'; // naive UTC
    $cases = [
        // [label, X-If-Modified-Since, stored, expected stale]
        ['ISO echo of the same instant is not stale', '2026-10-27T09:30:39+01:00', $stored, false],
        ['the same instant written in UTC is not stale', '2026-10-27T08:30:39+00:00', $stored, false],
        ['an ISO echo one second older IS stale', '2026-10-27T09:30:38+01:00', $stored, true],
        ['an ISO echo newer than the row is not stale', '2026-10-27T09:30:40+01:00', $stored, false],
        ['a raw naive-UTC echo of the same value is not stale', $stored, $stored, false],
        ['a raw naive-UTC echo one second older IS stale', '2026-10-27 08:30:38', $stored, true],
        ['KNOWN MASK: a page loaded before the deploy echoes the OLD session-zone value (Oslo wall clock), which reads an hour later than the migrated row, so a conflict is not seen', '2026-10-27 09:30:39', $stored, false],
        ['an empty header is no conflict', '', $stored, false],
        ['an unparseable header is no conflict', 'not a time', $stored, false],
        ['a row with no modified is no conflict', '2026-10-27T09:30:38+01:00', '', false],
    ];
    foreach (['Europe/Oslo', 'UTC', 'America/New_York'] as $zone) {
        date_default_timezone_set($zone);
        foreach ($cases as [$label, $header, $row, $want]) {
            $got = HospitalityController::isStaleVersion($header, $row === '' ? null : $row);
            $add("conflict check, PHP default zone {$zone}: {$label}", $got === $want, 'got ' . var_export($got, true));
        }
    }
    date_default_timezone_set('Europe/Oslo');
    return $out;
}

/**
 * One arm as a child process.
 *
 * stderr goes to a file, not a pipe: the arm's PHP notices can outgrow a pipe buffer while we are still
 * reading stdout, and that deadlocks both processes.
 *
 * @param array<string,string> $env the complete environment of the child
 * @return array{int,string,string} exit code, stdout, stderr
 */
function hd_spawn_arm(array $env): array
{
    $errFile = (string)tempnam(sys_get_temp_dir(), 'hdtz');
    $proc = proc_open(
        [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'xdebug.mode=off', __FILE__],
        [1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']],
        $pipes,
        null,
        $env
    );
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $rc = proc_close($proc);
    $err = (string)file_get_contents($errFile);
    @unlink($errFile);
    return [$rc, $out, $err];
}

/** The checks an arm printed: one JSON object per line. Anything else on stdout is not a check. */
function hd_arm_rows(string $out): array
{
    $rows = [];
    foreach (explode("\n", trim($out)) as $line) {
        $row = json_decode($line, true);
        if (is_array($row) && isset($row['n'])) {
            $rows[] = $row;
        }
    }
    return $rows;
}

/** The last lines of an arm's stderr, minus the deprecation notices, on one line. */
function hd_stderr_tail(string $err): string
{
    return implode(' | ', array_slice(array_values(array_filter(
        explode("\n", trim($err)),
        static fn($l) => $l !== '' && stripos($l, 'deprecated') === false
    )), -4));
}

// ======================================================================================================
// the arm guard: an arm drops tables, so it must be sure which database it is in
// ======================================================================================================

/**
 * The name pattern on its own, no database: the real application database, the maintenance databases and every
 * near miss of a scratch name must be refused, and only a name exactly like the master's accepted.
 *
 * @return list<array{string,bool,string}>
 */
function hd_scratch_name_checks(): array
{
    $out = [];
    $names = [
        'hd_tz_utc_4242_0a1b2c' => true,
        'hd_tz_oslo_7_ffffff' => true,
        'portico_ebe' => false,
        'postgres' => false,
        'template1' => false,
        '' => false,
        'hd_decoy_4242_0a1b2c' => false,
        'hd_tz_prod_4242_0a1b2c' => false,
        'hd_tz_utc__0a1b2c' => false,
        'hd_tz_utc_4242_0a1b2g' => false,
        'hd_tz_utc_4242_0a1b2' => false,
        'hd_tz_utc_4242_0a1b2c3' => false,
        'HD_TZ_UTC_4242_0A1B2C' => false,
        'xhd_tz_utc_4242_0a1b2c' => false,
        'hd_tz_utc_4242_0a1b2c_x' => false,
        "hd_tz_utc_4242_0a1b2c\n" => false,
    ];
    foreach ($names as $name => $accepted) {
        $got = preg_match(HD_SCRATCH_DB, (string)$name) === 1;
        $out[] = ['guard name pattern ' . ($accepted ? 'accepts ' : 'refuses ') . json_encode((string)$name), $got === $accepted, $got ? 'it matched' : 'it did not match'];
    }
    return $out;
}

/**
 * A read on the connection, through prepare/execute: on a Db that goes straight to PDO, whereas Db::query() is
 * the legacy method, which reads configuration the arm has not set yet.
 *
 * @param PDO|Db $connection
 */
function hd_select($connection, string $sql): PDOStatement
{
    $statement = $connection->prepare($sql);
    $statement->execute();
    return $statement;
}

/**
 * Every table, view and the like outside the system schemas, as schema.name.
 *
 * @param PDO|Db $connection
 * @return list<string>
 */
function hd_user_tables($connection): array
{
    return hd_select(
        $connection,
        "SELECT n.nspname || '.' || c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
         WHERE c.relkind IN ('r', 'p', 'v', 'm', 'f') AND left(n.nspname, 3) <> 'pg_' AND n.nspname <> 'information_schema'
         ORDER BY 1"
    )->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Why an arm must NOT run on this connection, or null if it may. It only reads: nothing is created, changed
 * or dropped before this has returned null.
 *
 * An arm runs only in the scratch database the master made for it: a name that looks like one of the master's,
 * the very name the master passed in HD_ARM_DB, and nothing in it that the harness did not create.
 */
function hd_arm_refusal(Db $db, string $armDb): ?string
{
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
        return 'the connection is not to Postgres, so there is no database name to check';
    }
    $current = (string)hd_select($db, 'SELECT current_database()')->fetchColumn();
    if (preg_match(HD_SCRATCH_DB, $current) !== 1) {
        return "it is connected to '{$current}', which is not a scratch database (hd_tz_utc_* or hd_tz_oslo_*, made by this harness)";
    }
    if ($armDb !== $current) {
        return 'HD_ARM_DB is ' . ($armDb === '' ? 'not set' : "'{$armDb}'") . ", not the database this arm is connected to ('{$current}'); an arm runs only in the database its master made for it";
    }
    $foreign = array_diff(hd_user_tables($db), array_map(static fn(string $t): string => "public.{$t}", HD_ARM_TABLES));
    if ($foreign) {
        return "'{$current}' holds tables this harness did not create (" . implode(', ', array_slice($foreign, 0, 5)) . '); a scratch database holds only the ones the arms make';
    }
    return null;
}

/**
 * The guard, tested end to end. An arm pointed at a database it must not touch has to exit 2, say why, run no
 * check, and leave that database exactly as it was, down to a canary row. Each scenario isolates ONE of the
 * guard's checks, so removing any one of them turns a scenario red. The last scenario is the control: with
 * every check satisfied, the same arm DOES drop and recreate the canary, so "the canary survived" above could
 * have said otherwise.
 *
 * Every database here is one the master created and drops. The decoys are why this is safe to mutate: with
 * the guard removed, the arms run in them and nothing outside the harness is reached.
 *
 * @param callable(string):string $dsnFor database name => DSN
 * @param array{decoy:string,spare:string,real:string} $names decoy: a name no arm may run in; spare: a scratch-style
 *        name the master never hands to an arm; real: a scratch database the master did make
 * @param array<string,string> $controlEnv the environment the client-zone arm needs, for the control
 * @return list<array{string,bool,string}>
 */
function hd_guard_checks(callable $dsnFor, string $user, string $pass, string $root, array $names, array $controlEnv): array
{
    $out = [];
    $add = static function (string $name, bool $ok, string $detail = '') use (&$out): void {
        $out[] = [$name, $ok, $detail];
    };
    $open = static fn(string $db): PDO => new PDO($dsnFor($db), $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $canary = static function (PDO $c): int {
        try {
            return (int)$c->query("SELECT count(*) FROM bb_user WHERE id = 424242 AND name = 'canary'")->fetchColumn();
        } catch (PDOException) {
            return 0; // the table is gone
        }
    };
    /** @return array{int,string,string} */
    $arm = static function (string $armName, ?string $dsn, ?string $armDb, array $extraEnv = []) use ($user, $pass, $root): array {
        $env = array_merge(getenv(), $extraEnv, [
            'HD_ARM' => $armName,
            'HD_ROOT' => $root,
            'HD_PG_DSN' => (string)$dsn,
            'HD_PG_USER' => $user,
            'HD_PG_PASS' => $pass,
        ]);
        unset($env['HD_ARM_DB']);
        if ($armDb !== null) {
            $env['HD_ARM_DB'] = $armDb;
        }
        return hd_spawn_arm($env);
    };
    $expectRefusal = static function (string $label, ?PDO $watch, string $armName, ?string $dsn, ?string $armDb, string $needle) use ($add, $arm, $canary): void {
        $before = $watch ? hd_user_tables($watch) : [];
        [$rc, $stdout, $stderr] = $arm($armName, $dsn, $armDb);
        $ran = count(hd_arm_rows($stdout));
        $add("guard [{$label}]: the arm exits 2", $rc === 2, "exit {$rc}: " . hd_stderr_tail($stderr));
        $add("guard [{$label}]: it says REFUSES and why ('{$needle}')", str_contains($stderr, 'REFUSES') && str_contains($stderr, $needle), hd_stderr_tail($stderr));
        $add("guard [{$label}]: it ran no check", $ran === 0, "{$ran} checks ran");
        if ($watch) {
            $add("guard [{$label}]: the canary row survived", $canary($watch) === 1);
            $add("guard [{$label}]: no table was created or dropped", hd_user_tables($watch) === $before, 'before ' . implode(',', $before) . ' / after ' . implode(',', hd_user_tables($watch)));
        }
    };

    // Back to one canary row in a table the arms DROP, and nothing else, before every scenario: a scenario that
    // wrongly lets the arm run must not take the next scenario's canary with it.
    $plant = static function (PDO $c): void {
        foreach (HD_ARM_TABLES as $t) {
            $c->exec("DROP TABLE IF EXISTS {$t} CASCADE");
        }
        $c->exec('DROP TABLE IF EXISTS bb_application');
        $c->exec('CREATE TABLE bb_user (id int, name text)');
        $c->exec("INSERT INTO bb_user VALUES (424242, 'canary')");
    };
    $decoy = $open($names['decoy']);
    $spare = $open($names['spare']);

    // the name is wrong; HD_ARM_DB agrees with it and nothing foreign is there, so ONLY the name check can refuse
    $plant($decoy);
    $expectRefusal('a database that is not named like a scratch one', $decoy, 'utc-db', $dsnFor($names['decoy']), $names['decoy'], 'not a scratch database');
    // the name is right; ONLY HD_ARM_DB can refuse
    $plant($spare);
    $expectRefusal('HD_ARM_DB names a different database', $spare, 'utc-db', $dsnFor($names['spare']), $names['real'], 'HD_ARM_DB');
    $plant($spare);
    $expectRefusal('HD_ARM_DB is not set', $spare, 'utc-db', $dsnFor($names['spare']), null, 'HD_ARM_DB');
    // name and HD_ARM_DB are right; ONLY the foreign table can refuse (the example from the brief: bb_application)
    $plant($spare);
    $spare->exec('CREATE TABLE bb_application (id int)');
    $spare->exec('INSERT INTO bb_application VALUES (1)');
    $expectRefusal('a table the harness did not create (bb_application)', $spare, 'utc-db', $dsnFor($names['spare']), $names['spare'], 'did not create');
    // no DSN at all is the SQLite stand-in: no database name to check
    $expectRefusal('no Postgres connection at all', null, 'utc-db', '', $names['spare'], 'not to Postgres');

    // the control: every check satisfied, so the arm must run and the canary must go
    $plant($spare);
    $before = hd_user_tables($spare);
    [$rc] = $arm('client-zone', $dsnFor($names['spare']), $names['spare'], $controlEnv);
    $add('guard control: an arm that is allowed exits 0', $rc === 0, "exit {$rc}");
    $add('guard control: ...and then the canary IS gone, so the canary checks above can fail', $canary($spare) === 0);
    $add('guard control: ...and the tables DID change, so the table checks above can fail', hd_user_tables($spare) !== $before);

    return $out;
}

// ======================================================================================================
// an arm: one child process, one database, one session zone
// ======================================================================================================
function hd_arm(string $arm, string $root): int
{
    date_default_timezone_set('Europe/Oslo'); // deliberately NOT UTC: a hidden dependence on PHP's zone would show
    $emit = static function (string $name, bool $ok, string $detail = ''): void {
        echo json_encode(['n' => $name, 'ok' => $ok, 'd' => $detail], JSON_UNESCAPED_UNICODE) . "\n";
    };

    $db = Db::getInstance((string)getenv('HD_PG_DSN'), (string)getenv('HD_PG_USER'), (string)getenv('HD_PG_PASS'));
    // Before anything else, and above all before the first DROP TABLE further down: that one would hit the
    // application's own tables in whatever database this process happens to be connected to.
    $refusal = hd_arm_refusal($db, (string)getenv('HD_ARM_DB'));
    if ($refusal !== null) {
        fwrite(STDERR, "run_tz.php: the '{$arm}' arm REFUSES to run: {$refusal}. Nothing was created, changed or dropped.\n");
        return 2;
    }
    $db->set_config(['db_type' => 'pgsql']);
    $emit('the arm talks to Postgres (not the SQLite stand-in)', $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql');

    $stmt = static function (string $sql, array $params = []) use ($db): PDOStatement {
        $s = $db->prepare($sql);
        $s->execute($params);
        return $s;
    };
    $one = static fn(string $sql, array $params = []) => $stmt($sql, $params)->fetchColumn();
    $exec = static function (string $sql) use ($db): void {
        $db->exec($sql);
    };

    $zoneSource = static fn(): string => (string)$one("SELECT setting || ' | ' || source FROM pg_settings WHERE name = 'TimeZone'");
    $expectedZone = ['utc-db' => 'Etc/UTC | configuration file', 'oslo-db' => 'Europe/Oslo | database', 'client-zone' => 'Europe/Oslo | client'][$arm];
    $emit("control: the session zone is what this arm claims ({$expectedZone})", $zoneSource() === $expectedZone, 'got ' . $zoneSource());
    $sessionIsOslo = str_starts_with($zoneSource(), 'Europe/Oslo');

    // --- schema ----------------------------------------------------------------------------------
    $schema = static function (string $default) use ($exec): void {
        foreach (HD_ARM_TABLES as $t) {
            $exec("DROP TABLE IF EXISTS {$t} CASCADE");
        }
        $exec("CREATE TABLE bb_resource (id int PRIMARY KEY, name text, cancellation_deadline_value int, cancellation_deadline_unit text)");
        $exec("CREATE TABLE bb_hospitality (id serial PRIMARY KEY, resource_id int, name text, description text, active int DEFAULT 1,
                remote_serving_enabled int DEFAULT 0, allow_on_site_hospitality int DEFAULT 0, include_in_checkout_payment int DEFAULT 0,
                order_by_time_value int, order_by_time_unit text, open_days int DEFAULT 127,
                created timestamp NOT NULL DEFAULT {$default}, modified timestamp NOT NULL DEFAULT {$default}, created_by int, modified_by int)");
        $exec("CREATE TABLE bb_hospitality_order (id serial PRIMARY KEY, application_id int, hospitality_id int, location_resource_id int, status text,
                comment text, special_requirements text, serving_time_iso timestamp,
                created timestamp NOT NULL DEFAULT {$default}, modified timestamp NOT NULL DEFAULT {$default}, created_by int, modified_by int)");
        $exec("CREATE TABLE bb_hospitality_order_changelog (id serial PRIMARY KEY, order_id int, case_officer_id int, booking_user_id int, change_type text,
                old_value text, new_value text, comment text, changed_at timestamp NOT NULL DEFAULT {$default})");
        $exec("CREATE TABLE phpgw_accounts (account_id int, account_lid text)");
        $exec("CREATE TABLE bb_user (id int, name text)");
        $exec("CREATE TABLE bb_hospitality_order_line (id serial, order_id int, hospitality_article_id int, quantity numeric, unit_price numeric, tax_code int, amount numeric, comment text)");
        $exec("CREATE TABLE bb_hospitality_article (id int, article_mapping_id int)");
        $exec("CREATE TABLE bb_article_mapping (id int, article_id int, article_cat_id int, unit text)");
        $exec("CREATE TABLE bb_article_view (id int, article_cat_id int, name text)");
        $exec("INSERT INTO bb_resource VALUES (3, 'R', 1, 'day')");
    };

    $oslo = new DateTimeZone('Europe/Oslo');
    $utc = new DateTimeZone('UTC');

    /** Seed one row per case in every table, stored the way the OLD writers did: instant::timestamptz::timestamp in THIS session. */
    $seedLegacy = static function () use ($exec): void {
        $exec("INSERT INTO bb_hospitality (id, resource_id, name, created_by) SELECT g, 3, 'H' || g, 1 FROM generate_series(1, " . count(HD_CASES) . ") g");
        $exec("INSERT INTO bb_hospitality_order (id, application_id, hospitality_id, location_resource_id, status, serving_time_iso)
               SELECT g, 50, 1, 3, 'pending', '2026-11-01 10:00:00' FROM generate_series(1, " . count(HD_CASES) . ") g");
        $i = 0;
        foreach (HD_CASES as $instant) {
            $i++;
            $exec("UPDATE bb_hospitality SET created = '{$instant}'::timestamptz::timestamp, modified = '{$instant}'::timestamptz::timestamp WHERE id = {$i}");
            $exec("UPDATE bb_hospitality_order SET created = '{$instant}'::timestamptz::timestamp, modified = '{$instant}'::timestamptz::timestamp WHERE id = {$i}");
            $exec("INSERT INTO bb_hospitality_order_changelog (order_id, change_type, comment, changed_at) VALUES ({$i}, 'applicant_change', 'x', '{$instant}'::timestamptz::timestamp)");
        }
    };

    $snapshot = static function () use ($one): string {
        return (string)$one(
            "SELECT coalesce((SELECT string_agg(id || ':' || created || '|' || modified, ',' ORDER BY id) FROM bb_hospitality), '') || '#'
                 || coalesce((SELECT string_agg(id || ':' || created || '|' || modified, ',' ORDER BY id) FROM bb_hospitality_order), '') || '#'
                 || coalesce((SELECT string_agg(id || ':' || changed_at, ',' ORDER BY id) FROM bb_hospitality_order_changelog), '')"
        );
    };
    $defaults = static function () use ($one): array {
        $out = [];
        foreach (HD_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $out["{$table}.{$column}"] = $one(
                    "SELECT pg_get_expr(d.adbin, d.adrelid) FROM pg_attrdef d JOIN pg_attribute a ON a.attrelid = d.adrelid AND a.attnum = d.adnum
                     WHERE d.adrelid = '{$table}'::regclass AND a.attname = '{$column}'"
                );
            }
        }
        return $out;
    };

    // --- the migration, run the way MigrationService::runPending runs it: one transaction ---------
    $migrationFile = $root . '/src/modules/booking/migrations/m20261027_hospitality_timestamps_to_utc.php';
    $schemaProc = (new ReflectionClass(\App\modules\phpgwapi\services\SchemaProc\SchemaProc::class))->newInstanceWithoutConstructor();
    $migrate = static function () use ($db, $migrationFile, $schemaProc): ?Throwable {
        $db->Exception_On_Error = true;
        $db->transaction_begin();
        try {
            $migration = require $migrationFile;
            $migration->setDependencies($db, $schemaProc);
            $migration->up();
            $db->transaction_commit();
            return null;
        } catch (Throwable $e) {
            $db->transaction_abort();
            return $e;
        } finally {
            $db->Exception_On_Error = false;
        }
    };
    $haveMigration = is_file($migrationFile);
    $emit('the migration file exists', $haveMigration, $migrationFile);

    $utcDefaults = array_fill_keys(array_merge(...array_map(
        static fn($t, $cols) => array_map(static fn($c) => "{$t}.{$c}", $cols),
        array_keys(HD_COLUMNS),
        array_values(HD_COLUMNS)
    )), HD_UTC_DEFAULT);

    // ==================================================================================================
    // T3. the migration
    // ==================================================================================================
    if ($haveMigration && $arm !== 'client-zone') {
        $schema('now()');
        $seedLegacy();
        $before = $snapshot();
        $err = $migrate();
        $emit('T3: the migration runs without error', $err === null, $err ? get_class($err) . ': ' . $err->getMessage() : '');

        if ($err === null) {
            $emit('T3: the five defaults now read exactly ' . HD_UTC_DEFAULT, $defaults() === $utcDefaults, json_encode($defaults()));
            $i = 0;
            foreach (HD_CASES as $label => $instant) {
                $i++;
                $truth = (new DateTimeImmutable($instant))->setTimezone($utc)->format('Y-m-d H:i:s.u');
                $lossy = $sessionIsOslo && str_contains($label, 'fall-back 1st pass');
                foreach (HD_COLUMNS as $table => $columns) {
                    foreach ($columns as $column) {
                        $key = $table === 'bb_hospitality_order_changelog' ? 'order_id' : 'id';
                        if ($lossy) {
                            $ok = (bool)$one("SELECT {$column} = CAST(:t AS timestamp) + interval '1 hour' FROM {$table} WHERE {$key} = {$i}", [':t' => $truth]);
                            $emit("T3 KNOWN LOSS: {$table}.{$column}, {$label}: the first pass of the repeated hour lands one hour late", $ok);
                        } else {
                            $ok = (bool)$one("SELECT {$column} = CAST(:t AS timestamp) FROM {$table} WHERE {$key} = {$i}", [':t' => $truth]);
                            $emit("T3: {$table}.{$column}, {$label}: stored as the true UTC", $ok, (string)$one("SELECT {$column}::text FROM {$table} WHERE {$key} = {$i}") . " want {$truth}");
                        }
                    }
                }
            }
            $once = $snapshot();
            $emit('T3: the session zone was ' . ($sessionIsOslo ? 'Oslo, so the rows moved' : 'UTC, so the rows did not'), $sessionIsOslo ? $once !== $before : $once === $before);
            $again = $migrate();
            $emit('T3: a second run does not fail', $again === null, $again ? $again->getMessage() : '');
            $emit('T3: a second run changes nothing (no double shift)', $snapshot() === $once);
            $emit('T3: ...and the defaults are still the UTC ones', $defaults() === $utcDefaults);
        }

        // --- refusals: each must throw, change no row and leave the defaults as they were -------
        $refusals = [
            'half-done defaults (one of five already UTC)' => [
                static function () use ($exec): void { $exec("ALTER TABLE bb_hospitality ALTER COLUMN created SET DEFAULT (now() AT TIME ZONE 'UTC')"); },
                'neither all the original ones nor all UTC',
            ],
            'an unrecognised default spelling (localtimestamp)' => [
                static function () use ($exec): void { $exec('ALTER TABLE bb_hospitality_order_changelog ALTER COLUMN changed_at SET DEFAULT localtimestamp'); },
                'neither all the original ones nor all UTC',
            ],
            'a column with no default at all' => [
                static function () use ($exec): void { $exec('ALTER TABLE bb_hospitality_order ALTER COLUMN modified DROP DEFAULT'); },
                '(none)',
            ],
        ];
        if ($arm === 'utc-db') {
            $refusals["a zone set in this session (SET TIME ZONE 'Europe/Oslo') - the app's sessions would not share it"] = [
                static function () use ($exec): void { $exec("SET TIME ZONE 'Europe/Oslo'"); },
                "comes from 'session'",
            ];
        }
        foreach ($refusals as $label => [$prepare, $needle]) {
            $schema('now()');
            $seedLegacy();
            $prepare();
            $rowsBefore = $snapshot();
            $defaultsBefore = $defaults();
            $err = $migrate();
            $exec('RESET TIME ZONE');
            $emit("T3 refusal ({$label}): it throws", $err !== null && str_contains($err->getMessage(), $needle), $err ? $err->getMessage() : 'it did not throw');
            $emit("T3 refusal ({$label}): no row changed", $snapshot() === $rowsBefore);
            $emit("T3 refusal ({$label}): the defaults are as they were", $defaults() === $defaultsBefore);
        }
    } elseif ($arm !== 'client-zone') {
        $emit('T3: the migration was exercised', false, 'no migration file');
    }

    // The zone arrives from the CLIENT here: the migration must refuse and change nothing.
    if ($arm === 'client-zone') {
        if ($haveMigration) {
            $schema('now()');
            $seedLegacy();
            $rowsBefore = $snapshot();
            $defaultsBefore = $defaults();
            $err = $migrate();
            $emit("T3 refusal (a zone from the client, PGOPTIONS): it throws", $err !== null && str_contains($err->getMessage(), "comes from 'client'"), $err ? $err->getMessage() : 'it did not throw');
            $emit('T3 refusal (a zone from the client): no row changed', $snapshot() === $rowsBefore);
            $emit('T3 refusal (a zone from the client): the defaults are as they were', $defaults() === $defaultsBefore);
        } else {
            $emit('T3 refusal (a zone from the client): exercised', false, 'no migration file');
        }
        return 0;
    }

    // ==================================================================================================
    // the target schema for T1, T2, T4: UTC defaults, written by hand so these do not depend on T3
    // ==================================================================================================
    $schema(HD_UTC_DEFAULT);
    $hospitalityRepo = new HospitalityRepository();
    $orderRepo = new HospitalityOrderRepository();

    // ==================================================================================================
    // T1. writers: now() is stored as UTC in this session's zone
    // ==================================================================================================
    $near = static fn(string $table, string $column, int $id, string $key = 'id') => (bool)$one(
        "SELECT abs(extract(epoch FROM ({$column} - (now() AT TIME ZONE 'UTC')))) < 120 FROM {$table} WHERE {$key} = {$id}"
    );
    $age = static function (string $table, string $columns, int $id, string $key = 'id') use ($exec): void {
        $set = implode(', ', array_map(static fn($c) => "{$c} = '2020-01-01 00:00:00'", explode(',', $columns)));
        $exec("UPDATE {$table} SET {$set} WHERE {$key} = {$id}");
    };
    $zoneName = $sessionIsOslo ? 'Oslo session' : 'UTC session';

    $hid = $hospitalityRepo->create(['resource_id' => 3, 'name' => 'T1', 'created_by' => 1]);
    $emit("T1 [{$zoneName}] HospitalityRepository::create: created is UTC now", $near('bb_hospitality', 'created', $hid));
    $emit("T1 [{$zoneName}] HospitalityRepository::create: modified is UTC now", $near('bb_hospitality', 'modified', $hid));
    $age('bb_hospitality', 'created,modified', $hid);
    $hospitalityRepo->update($hid, ['name' => 'T1b', 'modified_by' => 1]);
    $emit("T1 [{$zoneName}] HospitalityRepository::update: modified is UTC now", $near('bb_hospitality', 'modified', $hid));
    $emit("T1 [{$zoneName}] HospitalityRepository::update: created is left alone", (bool)$one("SELECT created = '2020-01-01' FROM bb_hospitality WHERE id = {$hid}"));
    $age('bb_hospitality', 'modified', $hid);
    $hospitalityRepo->delete($hid);
    $emit("T1 [{$zoneName}] HospitalityRepository::delete: modified is UTC now", $near('bb_hospitality', 'modified', $hid));

    $oid = $orderRepo->create(['application_id' => 50, 'hospitality_id' => $hid, 'location_resource_id' => 3, 'created_by' => 1]);
    $emit("T1 [{$zoneName}] HospitalityOrderRepository::create: created is UTC now", $near('bb_hospitality_order', 'created', $oid));
    $emit("T1 [{$zoneName}] HospitalityOrderRepository::create: modified is UTC now", $near('bb_hospitality_order', 'modified', $oid));
    $age('bb_hospitality_order', 'created,modified', $oid);
    $orderRepo->update($oid, ['comment' => 'changed', 'modified_by' => 1]);
    $emit("T1 [{$zoneName}] HospitalityOrderRepository::update: modified is UTC now", $near('bb_hospitality_order', 'modified', $oid));
    $emit("T1 [{$zoneName}] HospitalityOrderRepository::update: created is left alone", (bool)$one("SELECT created = '2020-01-01' FROM bb_hospitality_order WHERE id = {$oid}"));
    $age('bb_hospitality_order', 'modified', $oid);
    $orderRepo->updateStatus($oid, 'confirmed', 1);
    $emit("T1 [{$zoneName}] HospitalityOrderRepository::updateStatus: modified is UTC now", $near('bb_hospitality_order', 'modified', $oid));
    $age('bb_hospitality_order', 'modified', $oid);
    $orderRepo->delete($oid, 1);
    $emit("T1 [{$zoneName}] HospitalityOrderRepository::delete: modified is UTC now", $near('bb_hospitality_order', 'modified', $oid));
    $cid = $orderRepo->addChangelogEntry(['order_id' => $oid, 'change_type' => 'applicant_change', 'comment' => 'x']);
    $emit("T1 [{$zoneName}] HospitalityOrderRepository::addChangelogEntry: changed_at is UTC now", $near('bb_hospitality_order_changelog', 'changed_at', $cid));

    // ==================================================================================================
    // T2. readers: UTC stored reads back as the right instant, as an ISO string that carries an offset
    // ==================================================================================================
    $schema(HD_UTC_DEFAULT);
    $exec("INSERT INTO bb_hospitality (id, resource_id, name, created_by) SELECT g, 3, 'H' || g, 1 FROM generate_series(1, " . count(HD_CASES) . ") g");
    $exec("INSERT INTO bb_hospitality_order (id, application_id, hospitality_id, location_resource_id, status, serving_time_iso)
           SELECT g, 50, 1, 3, 'pending', '2026-11-01 10:00:00' FROM generate_series(1, " . count(HD_CASES) . ") g");
    $i = 0;
    foreach (HD_CASES as $instant) {
        $i++;
        $stored = "'{$instant}'::timestamptz AT TIME ZONE 'UTC'"; // what a UTC writer stores, whatever THIS session's zone is
        $exec("UPDATE bb_hospitality SET created = {$stored}, modified = {$stored} WHERE id = {$i}");
        $exec("UPDATE bb_hospitality_order SET created = {$stored}, modified = {$stored} WHERE id = {$i}");
        $exec("INSERT INTO bb_hospitality_order_changelog (order_id, change_type, comment, changed_at) VALUES ({$i}, 'applicant_change', 'x', {$stored})");
    }
    $verdict = static function (mixed $got, string $instant) use ($oslo, $utc): array {
        if (!is_string($got) || !preg_match('/(Z|[+-]\d{2}:\d{2})$/', $got)) {
            return [false, 'not an offset-bearing ISO string: ' . var_export($got, true)];
        }
        $true = new DateTimeImmutable($instant);
        $d = new DateTimeImmutable($got);
        $wall = $d->setTimezone($oslo)->format('Y-m-d H:i:s') === $true->setTimezone($oslo)->format('Y-m-d H:i:s');
        $same = $d->getTimestamp() === $true->getTimestamp();
        return [$wall && $same, "got {$got}, want Oslo " . $true->setTimezone($oslo)->format('c')];
    };
    $serialize = static fn(array $row) => (new HospitalityOrder($row))->serialize();
    $pick = static fn(array $rows, int $id) => array_values(array_filter($rows, static fn($r) => (int)$r['id'] === $id))[0] ?? [];
    $i = 0;
    foreach (HD_CASES as $label => $instant) {
        $i++;
        $readers = [
            'HospitalityOrderRepository::getById created' => $serialize($orderRepo->getById($i))['created'] ?? null,
            'HospitalityOrderRepository::getById modified' => $serialize($orderRepo->getById($i))['modified'] ?? null,
            'getByApplicationId created' => $serialize($pick($orderRepo->getByApplicationId(50), $i))['created'] ?? null,
            'getByApplicationIds created' => $serialize($pick($orderRepo->getByApplicationIds([50]), $i))['created'] ?? null,
            'getByHospitalityId created' => $serialize($pick($orderRepo->getByHospitalityId(1), $i))['created'] ?? null,
            'getOrderWithLines created' => $serialize($orderRepo->getOrderWithLines($i))['created'] ?? null,
            'getOrderWithLines changelog changed_at' => $serialize($orderRepo->getOrderWithLines($i))['changelog'][0]['changed_at'] ?? null,
            'getChangelog changed_at' => $orderRepo->getChangelog($i)[0]['changed_at'] ?? null,
            'getLastApplicantChanges (Endret av søker)' => $orderRepo->getLastApplicantChanges([$i])[$i] ?? null,
            'Hospitality model via HospitalityRepository::getById created' => (new Hospitality($hospitalityRepo->getById($i)))->serialize()['created'] ?? null,
            'Hospitality model via HospitalityRepository::getById modified' => (new Hospitality($hospitalityRepo->getById($i)))->serialize()['modified'] ?? null,
            'Hospitality model via HospitalityRepository::getAll created' => (new Hospitality($pick($hospitalityRepo->getAll(), $i)))->serialize()['created'] ?? null,
        ];
        foreach ($readers as $name => $got) {
            [$ok, $detail] = $verdict($got, $instant);
            $emit("T2 [{$zoneName}] {$name}, {$label}", $ok, $detail);
        }
    }
    $emit("T2 [{$zoneName}] serving_time_iso is untouched by all of this (explicit UTC)", ($serialize($orderRepo->getById(1))['serving_time_iso'] ?? null) === '2026-11-01T11:00:00+01:00');

    // ==================================================================================================
    // T4. deploy order, on the Oslo database only (on UTC the two conventions are the same)
    // ==================================================================================================
    if ($sessionIsOslo && $haveMigration) {
        $schema('now()'); // the install before the migration
        $hid = $hospitalityRepo->create(['resource_id' => 3, 'name' => 'T4', 'created_by' => 1]);
        $pre = $orderRepo->create(['application_id' => 60, 'hospitality_id' => $hid, 'location_resource_id' => 3, 'created_by' => 1]);
        $age('bb_hospitality_order', 'modified', $pre);
        $orderRepo->updateStatus($pre, 'confirmed', 1); // NEW code, migration not run yet: DEFAULT is still the old one
        $emit('T4 new code first: before the migration the row is in the session zone (this is what the migration will convert)', !$near('bb_hospitality_order', 'created', $pre));
        $err = $migrate();
        $emit('T4 the migration runs after the new code has written', $err === null, $err ? $err->getMessage() : '');
        $emit('T4 new code first: the row the new code wrote BEFORE the migration is UTC after it (created)', $near('bb_hospitality_order', 'created', $pre));
        $emit('T4 new code first: ...and its modified (written with DEFAULT)', $near('bb_hospitality_order', 'modified', $pre));
        $post = $orderRepo->create(['application_id' => 60, 'hospitality_id' => $hid, 'location_resource_id' => 3, 'created_by' => 1]);
        $age('bb_hospitality_order', 'modified', $post);
        $orderRepo->updateStatus($post, 'confirmed', 1);
        $emit('T4 new code first: a row the new code writes AFTER the migration is UTC (created)', $near('bb_hospitality_order', 'created', $post));
        $emit('T4 new code first: ...and its modified', $near('bb_hospitality_order', 'modified', $post));
        // OLD code after the migration: a model of it (the explicit NOW() it ran), not the old code itself.
        $exec("UPDATE bb_hospitality_order SET modified = NOW() WHERE id = {$post}");
        $off = (float)$one("SELECT extract(epoch FROM (modified - (now() AT TIME ZONE 'UTC'))) FROM bb_hospitality_order WHERE id = {$post}");
        $emit('T4 KNOWN RESIDUAL: old code that still writes modified with NOW() after the migration stores it in the session zone (+1 h winter / +2 h summer)', abs($off - 3600) < 120 || abs($off - 7200) < 120, "offset {$off} s");
    } elseif ($sessionIsOslo) {
        $emit('T4: the deploy-order cases were exercised', false, 'no migration file');
    }

    return 0;
}
