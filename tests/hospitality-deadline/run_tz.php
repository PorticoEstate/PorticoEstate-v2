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
 * Environment: HD_PG_DSN, HD_PG_USER, HD_PG_PASS (required); HD_ROOT = tree holding src/ (default:
 * this repo; mutate_tz.py points it at a mutated copy). Nothing here is read from the application's
 * own configuration and no credential is stored in the repo.
 *
 * Exit code: 0 all pass, 1 any failure, 2 bad environment, 3 skipped.
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

    // --- scratch databases ---------------------------------------------------------------------
    $suffix = getmypid() . '_' . bin2hex(random_bytes(3));
    $dbUtc = "hd_tz_utc_{$suffix}";
    $dbOslo = "hd_tz_oslo_{$suffix}";
    try {
        $admin = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (Throwable $e) {
        echo 'TZ harness: SKIPPED (cannot connect: ' . $e->getMessage() . ")\n";
        return 3;
    }
    $created = [];
    try {
        foreach ([$dbUtc, $dbOslo] as $name) {
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
                'HD_ROOT' => $root,
                'HD_PG_DSN' => $dsnFor($spec['db']),
                'HD_PG_USER' => (string)$user,
                'HD_PG_PASS' => (string)$pass,
            ]);
            // stderr goes to a file, not a pipe: the arm's PHP notices can outgrow a pipe buffer while we are
            // still reading stdout, and that deadlocks both processes.
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
            $n = 0;
            foreach (explode("\n", trim($out)) as $line) {
                $row = json_decode($line, true);
                if (is_array($row) && isset($row['n'])) {
                    $record("[{$armName}] " . $row['n'], (bool)$row['ok'], (string)($row['d'] ?? ''));
                    $n++;
                }
            }
            $armsRun[] = "{$armName}={$n}";
            if ($rc !== 0 || $n === 0) {
                $tail = implode(' | ', array_slice(array_values(array_filter(
                    explode("\n", trim($err)),
                    static fn($l) => $l !== '' && stripos($l, 'deprecated') === false
                )), -4));
                $record("[{$armName}] the arm ran to the end (exit {$rc}, {$n} checks)", false, $tail);
            }
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
        foreach (['bb_hospitality_order_changelog', 'bb_hospitality_order_line', 'bb_hospitality_order', 'bb_hospitality', 'bb_hospitality_article',
            'bb_article_mapping', 'bb_article_view', 'bb_resource', 'phpgw_accounts', 'bb_user'] as $t) {
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
