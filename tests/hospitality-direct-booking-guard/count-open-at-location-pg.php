<?php

/**
 * OM6 of #47045: the SQL of HospitalityOrderRepository::countOpenAtLocation, run against PostgreSQL, not a double.
 *
 *   php tests/hospitality-direct-booking-guard/count-open-at-location-pg.php [--config=<header.inc.php>] [--domain=default]
 *
 * run.php answers Q1 from a double, so it cannot see the SQL. This runner is the behavioural proof of the four cases of
 * "open" (HospitalityOrderRepository::countOpenAtLocation, the one definition that Q1 and the delivery-location rule share):
 *   - serving time NULL: open (positive control: the instrument must count it),
 *   - serving time already past: not open,
 *   - serving time ahead: open,
 *   - a final status (cancelled, delivered): not open, whatever the serving time.
 *
 * Each case inserts ONE order inside its own savepoint, counts through the REAL repository, and rolls the savepoint back. The
 * count is measured as a delta against the count before the insert, so orders that already exist at the same place do not matter.
 * The whole run is one transaction, rolled back at the end. The order ids are explicit and negative, so no sequence advances.
 * The order's foreign keys need a real application, hospitality and resource: the first of each is used and never written.
 *
 * It refuses to measure, rather than pass, when the connection is not PostgreSQL (a silent in-memory SQLite counts nothing),
 * when there is no fixture row to point at, or when the repository is not the one under this tree.
 *
 * The config file is read, not written: pass the served copy with --config when the checkout has none (config/header.inc.php
 * is not tracked). Credentials are never printed.
 *
 * Exit: 0 all rows pass, 1 a row failed, 2 nothing could be measured.
 */

$root = dirname(__DIR__, 2);
$options = getopt('', ['config::', 'domain::']);
$configPath = $options['config'] ?? $root . '/config/header.inc.php';
$domain = $options['domain'] ?? 'default';

require $root . '/vendor/autoload.php';
// App\ classes come from THIS tree's src, ahead of the vendor autoloader's mapping (an export's vendor/ may point at the served tree).
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'App\\')) {
        $file = $root . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
}, true, true);

use App\Database\Db;
use App\modules\booking\repositories\HospitalityOrderRepository;

$cannotMeasure = static function (string $why): void {
    fwrite(STDERR, "CANNOT MEASURE: {$why}\n");
    exit(2);
};

if (!is_file($configPath)) {
    $cannotMeasure("no config file at {$configPath} (pass --config=<path to header.inc.php>)");
}
$settings = require $configPath;
$config = $settings['phpgw_domain'][$domain] ?? null;
if ($config === null || ($config['db_type'] ?? '') !== 'postgres') {
    $cannotMeasure("domain '{$domain}' is not a PostgreSQL domain in {$configPath}");
}

// Db::getInstance() with no DSN would be a silent in-memory SQLite: connect explicitly, and make this the singleton the repository uses.
$db = Db::getInstance(Db::CreateDsn($config), $config['db_user'], $config['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (!$db->isConnected()) {
    $cannotMeasure('the PostgreSQL connection did not open');
}
$driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
if ($driver !== 'pgsql') {
    $cannotMeasure("the connection driver is '{$driver}', not pgsql: a count from it would be a false zero");
}

// Db::query() is the legacy phpgw method (it answers true, not a statement): read through prepare(), as the repository does.
$fixture = static function (string $sql) use ($db): int {
    $statement = $db->prepare($sql);
    $statement->execute();
    return (int)$statement->fetchColumn();
};
$applicationId = $fixture('SELECT id FROM bb_application ORDER BY id LIMIT 1');
$hospitalityId = $fixture('SELECT id FROM bb_hospitality ORDER BY id LIMIT 1');
$resourceId = $fixture('SELECT id FROM bb_resource ORDER BY id LIMIT 1');
if ($applicationId === 0 || $hospitalityId === 0 || $resourceId === 0) {
    $cannotMeasure('the database has no application, hospitality or resource row to point the fixture order at');
}

$repository = new HospitalityOrderRepository();
$repositoryFile = (string)realpath((new ReflectionClass($repository))->getFileName());
if (!str_starts_with($repositoryFile, (string)realpath($root . '/src') . '/')) {
    $cannotMeasure("the repository loaded from {$repositoryFile}, not from this tree");
}

// [label, serving_time_iso SQL, status, expected open count added by the one order]
$cases = [
    ['serving time NULL: open (positive control)', 'NULL', 'pending', 1],
    ['serving time already past: not open', "(now() AT TIME ZONE 'UTC') - interval '1 day'", 'pending', 0],
    ['serving time ahead: open', "(now() AT TIME ZONE 'UTC') + interval '7 days'", 'pending', 1],
    ['final status cancelled, serving time ahead: not open', "(now() AT TIME ZONE 'UTC') + interval '7 days'", 'cancelled', 0],
    ['final status delivered, serving time NULL: not open', 'NULL', 'delivered', 0],
];

$insert = "INSERT INTO bb_hospitality_order (id, application_id, hospitality_id, location_resource_id, status, serving_time_iso)
    VALUES (:id, :application_id, :hospitality_id, :resource_id, :status, %s)";

$rows = [];
$db->beginTransaction();
try {
    foreach ($cases as $index => [$label, $servingSql, $status, $expected]) {
        $before = $repository->countOpenAtLocation($hospitalityId, $resourceId);
        $db->exec('SAVEPOINT om6_case');
        $db->prepare(sprintf($insert, $servingSql))->execute([
            ':id' => -900000 - $index - 1,
            ':application_id' => $applicationId,
            ':hospitality_id' => $hospitalityId,
            ':resource_id' => $resourceId,
            ':status' => $status,
        ]);
        $after = $repository->countOpenAtLocation($hospitalityId, $resourceId);
        $db->exec('ROLLBACK TO SAVEPOINT om6_case');
        $db->exec('RELEASE SAVEPOINT om6_case');
        $rows[] = [$label, $after - $before, $expected];
    }
} finally {
    $db->rollBack();
}

// Nothing was left behind: the fixture ids are the only negative ids this runner writes.
$left = $fixture('SELECT count(*) FROM bb_hospitality_order WHERE id BETWEEN -900099 AND -900000');
$rows[] = ['rolled back: no fixture order remains', $left, 0];

$failures = 0;
foreach ($rows as [$label, $actual, $expected]) {
    if ($actual !== $expected) {
        $failures++;
        echo "FAIL [{$label}] got {$actual} want {$expected}\n";
    }
}
$total = count($rows);
if ($failures > 0) {
    echo "RED: {$failures} of {$total} rows failed\n";
    exit(1);
}
echo "GREEN: all {$total} rows pass (countOpenAtLocation on pgsql, rolled back)\n";
exit(0);
