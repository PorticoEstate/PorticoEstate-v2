<?php

/**
 * #1424 «Tydeliggjøre at et rom har bevertning»: does Resource::has_hospitality say exactly what the citizen checkout offers?
 * Census on PostgreSQL, over EVERY resource, with the REAL repository, the REAL ResourceRepository and the three REAL endpoints.
 *
 *   php tests/hospitality-marking/census-pg.php [--config=<header.inc.php>] [--domain=default]
 *
 * THE ORACLE is the checkout's own method, not a second copy of its WHERE: for each resource in bb_resource,
 * HospitalityRepository::getActiveByResourceIds([$id]) (what HospitalityOrderController and the admin order view call). A resource
 * «has hospitality» when that answer is non-empty. Then, for every resource:
 *
 *   1. HospitalityRepository::getResourceIdsWithHospitality() (the set form, one query) contains it exactly when the oracle says so;
 *   2. GET /bookingfrontend/searchdataalloptimised (DataStore::SearchDataAllOptimised, serialised SHORT, as the search page gets it),
 *      GET /bookingfrontend/resources/{id} (ResourceController::getResource) and
 *      GET /bookingfrontend/buildings/{id}/resources (ResourceController::getResourcesByBuilding, short and full)
 *      carry has_hospitality, as a real boolean, equal to the oracle's answer. A missing key is a failure (the serialiser drops null).
 *   3. NO N+1: one request holds exactly ONE statement on bb_hospitality, however many resources it returns (a statement counter on the PDO).
 *
 * Classes of resource, from the DATA (independent SQL, not from the code under test): on-site own resource, active delivery location of a
 * delivery-only hospitality, on-site connected room, and the three that must NOT be offered (own resource of a delivery-only hospitality,
 * inactive location of an active one, anything of an inactive one) plus resources with no hospitality. Each class must have members, or the run
 * answers «cannot measure» (exit 2) instead of passing: a rule that is never held against a member proves nothing. The oracle is held to
 * the class too, so that a oracle that said «no» to everything could not pass.
 *
 * Reads only (SELECT); nothing is written. Env overrides, used by mutate-pg.py to show each broken copy turns this runner red:
 *   REPOSITORY_FILE, RESOURCE_REPOSITORY_FILE, RESOURCE_MODEL_FILE, DATASTORE_FILE, RESOURCE_CONTROLLER_FILE.
 * It refuses to measure when the connection is not PostgreSQL (a silent in-memory SQLite answers nothing).
 *
 * Exit: 0 all rows pass, 1 a row failed, 2 nothing could be measured.
 */

error_reporting(E_ALL & ~E_DEPRECATED); // SerializableTrait still calls setAccessible(), deprecated since PHP 8.5

$root = dirname(__DIR__, 2);
$options = getopt('', ['config::', 'domain::']);
$configPath = $options['config'] ?? $root . '/config/header.inc.php';
$domain = $options['domain'] ?? 'default';

$cannotMeasure = static function (string $why): void {
    fwrite(STDERR, "CANNOT MEASURE: {$why}\n");
    exit(2);
};

require $root . '/vendor/autoload.php';
// App\ classes come from THIS tree's src, ahead of the vendor autoloader's mapping (an export's vendor/ may point at the served tree).
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'App\\')) {
        $file = $root . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
}, true, true);

/** lang() answers "key|arg|arg": nothing on these paths needs a translation table. */
function lang($key, ...$args): string
{
    return implode('|', array_merge([$key], array_map('strval', $args)));
}

$sourceOf = static fn(string $override, string $default): string => getenv($override) ?: $root . '/src/' . $default;
require_once $sourceOf('REPOSITORY_FILE', 'modules/booking/repositories/HospitalityRepository.php');
require_once $sourceOf('RESOURCE_MODEL_FILE', 'modules/bookingfrontend/models/Resource.php');
require_once $sourceOf('RESOURCE_REPOSITORY_FILE', 'modules/bookingfrontend/repositories/ResourceRepository.php');
require_once $sourceOf('DATASTORE_FILE', 'modules/bookingfrontend/controllers/DataStore.php');
require_once $sourceOf('RESOURCE_CONTROLLER_FILE', 'modules/bookingfrontend/controllers/ResourceController.php');

use App\Database\Db;
use App\modules\booking\repositories\HospitalityRepository;
use App\modules\bookingfrontend\controllers\DataStore;
use App\modules\bookingfrontend\controllers\ResourceController;
use App\modules\bookingfrontend\models\Resource;
use App\modules\bookingfrontend\repositories\ResourceRepository;

/** Counts every statement the code under test executes, so «one query however many resources» can be asserted, not assumed. */
final class CountingStatement extends PDOStatement
{
    public static array $executed = [];

    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        self::$executed[] = $this->queryString;
        return parent::execute($params);
    }
}

if (!is_file($configPath)) {
    $cannotMeasure("no config file at {$configPath} (pass --config=<path to header.inc.php>)");
}
$settings = require $configPath;
$config = $settings['phpgw_domain'][$domain] ?? null;
if ($config === null || ($config['db_type'] ?? '') !== 'postgres') {
    $cannotMeasure("domain '{$domain}' is not a PostgreSQL domain in {$configPath}");
}
// Db::getInstance() with no DSN would be a silent in-memory SQLite: connect explicitly, and make this the singleton the repositories use.
$db = Db::getInstance(Db::CreateDsn($config), $config['db_user'], $config['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (!$db->isConnected() || $db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
    $cannotMeasure('the connection did not open, or it is not pgsql: a result from it would be a false zero');
}
$db->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CountingStatement::class]);

$fromThisTree = static fn(string $class, string $override, string $default): bool =>
    realpath((new ReflectionClass($class))->getFileName()) === realpath($sourceOf($override, $default));
$rows = [];
$expect = static function (string $name, $actual, $expected) use (&$rows): void {
    $rows[] = [$name, $actual, $expected];
};
$expect('env/the repository that runs is REPOSITORY_FILE or this tree\'s', $fromThisTree(HospitalityRepository::class, 'REPOSITORY_FILE', 'modules/booking/repositories/HospitalityRepository.php'), true);
$expect('env/the Resource model that runs is RESOURCE_MODEL_FILE or this tree\'s', $fromThisTree(Resource::class, 'RESOURCE_MODEL_FILE', 'modules/bookingfrontend/models/Resource.php'), true);
$expect('env/the ResourceRepository that runs is RESOURCE_REPOSITORY_FILE or this tree\'s', $fromThisTree(ResourceRepository::class, 'RESOURCE_REPOSITORY_FILE', 'modules/bookingfrontend/repositories/ResourceRepository.php'), true);
$expect('env/the DataStore that runs is DATASTORE_FILE or this tree\'s', $fromThisTree(DataStore::class, 'DATASTORE_FILE', 'modules/bookingfrontend/controllers/DataStore.php'), true);
$expect('env/the ResourceController that runs is RESOURCE_CONTROLLER_FILE or this tree\'s', $fromThisTree(ResourceController::class, 'RESOURCE_CONTROLLER_FILE', 'modules/bookingfrontend/controllers/ResourceController.php'), true);

$all = static function (string $sql) use ($db): array {
    $statement = $db->prepare($sql);
    $statement->execute();
    return $statement->fetchAll(PDO::FETCH_ASSOC);
};
$ids = static fn(string $sql): array => array_map('intval', array_column($all($sql), 'id'));

// ── 1. the classes of resource, from the data (not from the code under test) ────────────────────────────────────────────────
$allResourceIds = $ids('SELECT id FROM bb_resource ORDER BY id');
$classSql = [
    'on-site own resource (own resource of an active hospitality with on-site service)'
        => 'SELECT DISTINCT h.resource_id AS id FROM bb_hospitality h WHERE h.active = 1 AND h.allow_on_site_hospitality = 1',
    'delivery room (active delivery location of an active delivery-only hospitality)'
        => 'SELECT DISTINCT rl.resource_id AS id FROM bb_hospitality_remote_location rl JOIN bb_hospitality h ON h.id = rl.hospitality_id
             WHERE h.active = 1 AND rl.active = 1 AND h.allow_on_site_hospitality = 0 AND h.remote_serving_enabled = 1',
    'on-site connected room (active location of an active hospitality with on-site service)'
        => 'SELECT DISTINCT rl.resource_id AS id FROM bb_hospitality_remote_location rl JOIN bb_hospitality h ON h.id = rl.hospitality_id
             WHERE h.active = 1 AND rl.active = 1 AND h.allow_on_site_hospitality = 1',
];
$blockedSql = [
    'own resource of an active delivery-only hospitality (no on-site service)'
        => 'SELECT DISTINCT h.resource_id AS id FROM bb_hospitality h WHERE h.active = 1 AND h.allow_on_site_hospitality = 0 AND h.remote_serving_enabled = 1',
    'INACTIVE delivery location of an active hospitality'
        => 'SELECT DISTINCT rl.resource_id AS id FROM bb_hospitality_remote_location rl JOIN bb_hospitality h ON h.id = rl.hospitality_id
             WHERE h.active = 1 AND rl.active <> 1 AND (h.allow_on_site_hospitality = 1 OR h.remote_serving_enabled = 1)',
    'own resource or location of an INACTIVE hospitality'
        => 'SELECT h.resource_id AS id FROM bb_hospitality h WHERE h.active <> 1
             UNION SELECT rl.resource_id FROM bb_hospitality_remote_location rl JOIN bb_hospitality h ON h.id = rl.hospitality_id WHERE h.active <> 1',
];
$offeringIds = [];
$members = [];
foreach ($classSql as $name => $sql) {
    $members[$name] = $ids($sql);
    $offeringIds = array_merge($offeringIds, $members[$name]);
}
$offeringIds = array_values(array_unique($offeringIds));
$anyHospitality = array_values(array_unique(array_merge(
    $ids('SELECT resource_id AS id FROM bb_hospitality'),
    $ids('SELECT resource_id AS id FROM bb_hospitality_remote_location')
)));
// A blocked class can only be held to «not offered» for the members no offering class also reaches (a room can sit in several hospitalities).
$pureBlocked = [];
foreach ($blockedSql as $name => $sql) {
    $pureBlocked[$name] = array_values(array_diff($ids($sql), $offeringIds));
}
$pureBlocked['no hospitality at all'] = array_values(array_diff($allResourceIds, $anyHospitality));
foreach (array_merge($members, $pureBlocked) as $name => $list) {
    if (empty($list)) {
        $cannotMeasure("the database has no resource of the class «{$name}»: a rule that is never held against one proves nothing");
    }
}
$expectedOffered = array_values(array_unique(array_merge(...array_values($members))));
$expectedNot = array_values(array_unique(array_merge(...array_values($pureBlocked))));
$expect('classes/the offering classes and the blocked ones do not overlap (the data and the classes agree)', array_values(array_intersect($expectedOffered, $expectedNot)), []);

// ── 2. THE ORACLE: the real checkout method, for EVERY resource ─────────────────────────────────────────────────────────────────
$repository = new HospitalityRepository();
$oracle = [];
foreach ($allResourceIds as $id) {
    $oracle[$id] = !empty($repository->getActiveByResourceIds([$id]));
}
$expect('oracle/it says yes to at least one resource and no to at least one (the instrument can fire both ways)',
    [in_array(true, $oracle, true), in_array(false, $oracle, true)], [true, true]);
$expect('oracle/every member of an offering class is a yes (the oracle agrees with the data)',
    array_values(array_filter($expectedOffered, static fn(int $id) => ($oracle[$id] ?? null) !== true)), []);
$expect('oracle/every pure member of a blocked class, and every resource with no hospitality at all, is a no',
    array_values(array_filter($expectedNot, static fn(int $id) => ($oracle[$id] ?? null) !== false)), []);

// ── 3. the set form ─────────────────────────────────────────────────────────────────────────────────────────────────────────────
$set = $repository->getResourceIdsWithHospitality();
$expect('set/an ascending list of unique integers',
    [array_is_list($set), $set === array_values(array_unique($set)), $set === array_map('intval', $set), $set === (static function (array $s) { sort($s); return $s; })($set)], [true, true, true, true]);
$inSet = array_flip($set);
$disagree = [];
foreach ($allResourceIds as $id) {
    if (isset($inSet[$id]) !== $oracle[$id]) {
        $disagree[] = $id . ($oracle[$id] ? ' (checkout offers, set does not)' : ' (set offers, checkout does not)');
    }
}
$expect('set/getResourceIdsWithHospitality() holds a resource exactly when getActiveByResourceIds([id]) is non-empty, over all ' . count($allResourceIds) . ' resources (disagreements)', $disagree, []);
$expect('set/it names no resource that does not exist', array_values(array_diff($set, $allResourceIds)), []);
$expect('set/every member of an offering class is in it, every pure member of a blocked class is not',
    [array_values(array_diff($expectedOffered, $set)), array_values(array_intersect($expectedNot, $set))], [[], []]);

// ── 4. the three endpoints, driven for real ───────────────────────────────────────────────────────────────────────────────────
$request = static fn(array $query = []) => (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', 'https://pe-api.test/bookingfrontend/')->withQueryParams($query);
$call = static function (callable $handler) use ($db): array {
    CountingStatement::$executed = [];
    try {
        $response = $handler();
        $status = $response->getStatusCode();
        $json = json_decode((string)$response->getBody(), true);
    } catch (Throwable $e) {
        return [0, ['threw' => get_class($e) . ': ' . $e->getMessage()], []];
    }
    $onHospitality = array_values(array_filter(CountingStatement::$executed, static fn(string $sql) => stripos($sql, 'bb_hospitality') !== false));
    return [$status, $json ?? [], $onHospitality];
};
/** A real boolean equal to the oracle, for each resource id of a payload; returns the ids that fail and which of true/false were seen. */
$judge = static function (array $items) use ($oracle): array {
    $bad = [];
    $seen = [];
    foreach ($items as $item) {
        $id = (int)($item['id'] ?? 0);
        $flag = $item['has_hospitality'] ?? null;
        $seen[json_encode($flag)] = true;
        if (!is_bool($flag) || !array_key_exists($id, $oracle) || $flag !== $oracle[$id]) {
            $bad[] = $id . ' (' . json_encode($flag) . ' vs checkout ' . json_encode($oracle[$id] ?? null) . ')';
        }
    }
    return [$bad, isset($seen['true']), isset($seen['false'])];
};

$container = new class implements Psr\Container\ContainerInterface {
    public function get(string $id)
    {
        throw new RuntimeException("no service {$id}");
    }

    public function has(string $id): bool
    {
        return false;
    }
};
$dataStore = new DataStore($container);
$resourceController = (new ReflectionClass(ResourceController::class))->newInstanceWithoutConstructor();
foreach (['db' => $db, 'userSettings' => [], 'resourceRepository' => new ResourceRepository()] as $property => $value) {
    (new ReflectionProperty(ResourceController::class, $property))->setValue($resourceController, $value);
}

// 4a. the search data (the cards on the search page and the multi-domain merge read it)
[$status, $json, $statements] = $call(fn() => $dataStore->SearchDataAllOptimised($request(), new Slim\Psr7\Response()));
$payload = is_array($json['resources'] ?? null) ? $json['resources'] : [];
[$bad, $sawTrue, $sawFalse] = $judge($payload);
$expect('search-data/200 with resources', [$status, count($payload) > 0, $json['threw'] ?? null], [200, true, null]);
$expect('search-data/every resource of the payload carries has_hospitality as a boolean equal to the checkout\'s answer, over ' . count($payload) . ' resources (failures)', $bad, []);
$expect('search-data/the payload holds both a true and a false (the instrument can fire both ways)', [$sawTrue, $sawFalse], [true, true]);
$expect('search-data/ONE statement on bb_hospitality in the whole request (no N+1)', count($statements), 1);

// 4b. one resource (the resource page and the info dialog read it), for EVERY resource
$bad = [];
$worst = 0;
foreach ($allResourceIds as $id) {
    [$status, $json, $statements] = $call(fn() => $resourceController->getResource($request(), new Slim\Psr7\Response(), ['id' => (string)$id]));
    $worst = max($worst, count($statements));
    if ($status !== 200) {
        $bad[] = "{$id} (HTTP {$status} " . json_encode($json['threw'] ?? $json['error'] ?? null) . ')';
        continue;
    }
    [$wrong] = $judge([$json]);
    array_push($bad, ...$wrong);
}
$expect('resource/GET /resources/{id} carries has_hospitality as a boolean equal to the checkout\'s answer, for ALL ' . count($allResourceIds) . ' resources (failures)', $bad, []);
$expect('resource/the most statements on bb_hospitality any ONE request issues is exactly 1, over all of them (no N+1)', $worst, 1);

// 4c. every resource of a building (the calendar's room list and the application form read it), for EVERY building, full and short
$buildingIds = $ids('SELECT id FROM bb_building ORDER BY id');
$largest = $all('SELECT building_id AS id, count(*) AS n FROM bb_building_resource GROUP BY building_id ORDER BY n DESC LIMIT 1')[0] ?? ['id' => 0, 'n' => 0];
foreach (['full' => [], 'short' => ['short' => '1']] as $shape => $extra) {
    $bad = [];
    $trueSeen = $falseSeen = false;
    $checked = 0;
    $worst = 0;
    foreach ($buildingIds as $buildingId) {
        [$status, $json, $statements] = $call(fn() => $resourceController->getResourcesByBuilding($request(['results' => '-1'] + $extra), new Slim\Psr7\Response(), ['id' => (string)$buildingId]));
        $worst = max($worst, count($statements));
        if ($status !== 200) {
            $bad[] = "building {$buildingId} (HTTP {$status} " . json_encode($json['threw'] ?? $json['error'] ?? null) . ')';
            continue;
        }
        $results = $json['results'] ?? [];
        $checked += count($results);
        [$wrong, $t, $f] = $judge($results);
        $trueSeen = $trueSeen || $t;
        $falseSeen = $falseSeen || $f;
        array_push($bad, ...array_map(static fn(string $w) => "building {$buildingId}: {$w}", $wrong));
    }
    $expect("building-resources/{$shape}: every resource of every building carries has_hospitality as a boolean equal to the checkout's answer, over {$checked} rows in " . count($buildingIds) . ' buildings (failures)', $bad, []);
    $expect("building-resources/{$shape}: the rows hold both a true and a false", [$trueSeen, $falseSeen], [true, true]);
    $expect("building-resources/{$shape}: the most statements on bb_hospitality any ONE request issues is exactly 1, in all " . count($buildingIds) . " buildings, the largest holding {$largest['n']} resources (no N+1)", $worst, 1);
}
$expect('building-resources/the largest building holds more than one resource (a per-resource query would show as more than one statement)', (int)$largest['n'] > 1, true);

// ── verdict ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
$failures = 0;
foreach ($rows as [$name, $actual, $expected]) {
    if ($actual !== $expected) {
        $failures++;
        echo "FAIL [{$name}] got " . json_encode($actual) . ' want ' . json_encode($expected) . "\n";
    }
}
$total = count($rows);
if ($failures > 0) {
    echo "RED: {$failures} of {$total} rows failed\n";
    exit(1);
}
echo "GREEN: all {$total} rows pass (" . count($allResourceIds) . ' resources, ' . count($set) . ' offered, ' . count($buildingIds) . " buildings, 3 endpoints)\n";
exit(0);
