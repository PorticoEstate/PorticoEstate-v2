<?php

/**
 * (B) of #47994 (C2): which main resources HospitalityController accepts, on PostgreSQL, with the REAL repository and the REAL
 * direct-booking guard, held against what the admin's PICKER offers.
 *
 *   php tests/hospitality-direct-booking-guard/main-resource-offer-pg.php [--config=<header.inc.php>] [--domain=default]
 *
 * run.php answers the offer from a double, so it cannot see the SQL of HospitalityRepository::mainResourceOffer. This runner is the
 * behavioural proof, in two parts:
 *
 *   1. THE PICKER'S OWN CODE is the oracle, not a second copy of its WHERE: the buildings of `bb_building WHERE active = 1` (the SELECT of
 *      booking BuildingController::index, whose text a row below checks is still there) and, for each, the REAL
 *      bookingfrontend ResourceRepository::getByBuildingId, give the set of resources the picker can reach. Then, for EVERY resource in
 *      bb_resource, mainResourceOffer() must say offered (active AND in an active building) exactly when the picker reaches it.
 *   2. THE CONTROLLER on that data, inside one transaction that is rolled back: a fixture hospitality (explicit negative id) is moved with
 *      PUT to a sample of each class (inactive, active in no building, active only in inactive buildings, offered, offered but in ANOTHER
 *      building than the fixture's) and POSTed to refused classes. A refusal must be a 422 with the right code, the row unchanged and no
 *      row inserted; an offered resource must move. A main that has since gone inactive still saves a rename (the unchanged key), while
 *      leaving it for another inactive resource is refused.
 *
 * Each class must have members, or the run answers «cannot measure» instead of passing: an empty class would prove nothing.
 * REPOSITORY_FILE and CONTROLLER_FILE (optional) point at alternative copies of HospitalityRepository and HospitalityController, which
 * mutate-offer-pg.py uses to show each deliberately broken copy turns this runner red.
 *
 * It refuses to measure when the connection is not PostgreSQL (a silent in-memory SQLite answers nothing), and the code under test must
 * come from this tree. The config file is read, never written; credentials are never printed. The id sequence of bb_hospitality is not
 * advanced (the fixture id is explicit, and only refusals are POSTed).
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

/** lang() answers "key|arg|arg": enough to see WHICH text a refusal carries. */
function lang($key, ...$args): string
{
    return implode('|', array_merge([$key], array_map('strval', $args)));
}

/** The WebSocket fork is replaced by a recorder (the real one forks and talks to Redis). */
final class OfferWebSocketHelper
{
    public static function sendEntityNotificationAsync(string $entityType, $entityId, string $message, string $action, array $data = []): bool
    {
        return true;
    }
}
class_alias(OfferWebSocketHelper::class, 'App\modules\bookingfrontend\helpers\WebSocketHelper');

require_once getenv('REPOSITORY_FILE') ?: $root . '/src/modules/booking/repositories/HospitalityRepository.php';
require_once getenv('CONTROLLER_FILE') ?: $root . '/src/modules/booking/controllers/HospitalityController.php';

use App\Database\Db;
use App\modules\booking\controllers\HospitalityController;
use App\modules\booking\repositories\HospitalityOrderRepository;
use App\modules\booking\repositories\HospitalityRepository;
use App\modules\booking\services\HospitalityDirectBookingGuard;
use App\modules\bookingfrontend\repositories\ResourceRepository;
use App\modules\phpgwapi\security\Acl;

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

$fromThisTree = static fn(string $class, string $override): bool =>
    realpath((new ReflectionClass($class))->getFileName()) === realpath(getenv($override) ?: $root . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php');
$rows = [];
$expect = static function (string $name, $actual, $expected) use (&$rows): void {
    $rows[] = [$name, $actual, $expected];
};
$expect('env/the repository that runs is REPOSITORY_FILE or this tree\'s', $fromThisTree(HospitalityRepository::class, 'REPOSITORY_FILE'), true);
$expect('env/the controller that runs is CONTROLLER_FILE or this tree\'s', $fromThisTree(HospitalityController::class, 'CONTROLLER_FILE'), true);

$all = static function (string $sql) use ($db): array {
    $statement = $db->prepare($sql);
    $statement->execute();
    return $statement->fetchAll(PDO::FETCH_ASSOC);
};
$one = static function (string $sql) use ($all) {
    $rows = $all($sql);
    return $rows ? array_values($rows[0])[0] : null;
};

// ── 1. the classes of resource, from the data (not from the code under test) ────────────────────────────────────────────────
$classOf = [];   // resource id => 'inactive' | 'no building' | 'only inactive buildings' | 'offered'
foreach ($all("SELECT r.id, r.active,
                      (SELECT count(*) FROM bb_building_resource br WHERE br.resource_id = r.id) AS n_all,
                      (SELECT count(*) FROM bb_building_resource br JOIN bb_building b ON b.id = br.building_id
                        WHERE br.resource_id = r.id AND b.active = 1) AS n_active
                 FROM bb_resource r ORDER BY r.id") as $r) {
    $classOf[(int)$r['id']] = (int)$r['active'] !== 1 ? 'inactive'
        : ((int)$r['n_all'] === 0 ? 'no building' : ((int)$r['n_active'] === 0 ? 'only inactive buildings' : 'offered'));
}
$members = [];
foreach ($classOf as $id => $class) {
    $members[$class][] = $id;
}
foreach (['inactive', 'no building', 'only inactive buildings', 'offered'] as $class) {
    if (empty($members[$class])) {
        $cannotMeasure("the database has no resource of the class «{$class}»: a rule that is never tested against one proves nothing");
    }
}

// The oracle: the picker's own code, over the buildings its building search lists.
$pickerSelectStillThere = str_contains((string)file_get_contents($root . '/src/modules/booking/controllers/BuildingController.php'), 'SELECT b.* FROM bb_building b WHERE b.active = 1');
$expect('oracle/booking BuildingController::index still lists buildings with `WHERE b.active = 1` (the SELECT this runner copies)', $pickerSelectStillThere, true);
$resourceRepository = new ResourceRepository();
$reachable = [];
$buildingIds = array_map('intval', array_column($all('SELECT id FROM bb_building WHERE active = 1 ORDER BY id'), 'id'));
foreach ($buildingIds as $buildingId) {
    foreach ($resourceRepository->getByBuildingId($buildingId) as $resource) {
        $reachable[(int)$resource->id] = true;
    }
}
$expect('oracle/the picker reaches exactly the resources classed «offered» by the data (the oracle and the data agree)',
    [array_values(array_diff(array_keys($reachable), $members['offered'])), array_values(array_diff($members['offered'], array_keys($reachable)))], [[], []]);

// ── 2. the repository's own answer, for EVERY resource ─────────────────────────────────────────────────────────────────────────
$repository = new HospitalityRepository();
$expect('repository/mainResourceOffer() exists', method_exists($repository, 'mainResourceOffer'), true);
if (method_exists($repository, 'mainResourceOffer')) {
    $disagree = [];
    foreach ($classOf as $id => $class) {
        $offer = $repository->mainResourceOffer($id);
        $offered = $offer !== null && $offer['active'] && $offer['in_active_building'];
        if ($offered !== isset($reachable[$id])) {
            $disagree[] = "{$id} ({$class})";
        }
    }
    $expect('repository/mainResourceOffer() says «offered» for exactly the resources the picker reaches, over all ' . count($classOf) . ' resources (disagreements)', $disagree, []);
    $expect('repository/mainResourceOffer() answers null for a resource that does not exist', $repository->mainResourceOffer(2147483000), null);
    $inactive = $repository->mainResourceOffer($members['inactive'][0]);
    $expect('repository/an inactive resource: active false', $inactive['active'] ?? null, false);
    $none = $repository->mainResourceOffer($members['no building'][0]);
    $expect('repository/an active resource in no building: active true, in_active_building false', [$none['active'] ?? null, $none['in_active_building'] ?? null], [true, false]);
    $onlyInactive = $repository->mainResourceOffer($members['only inactive buildings'][0]);
    $expect('repository/an active resource only in inactive buildings: active true, in_active_building false', [$onlyInactive['active'] ?? null, $onlyInactive['in_active_building'] ?? null], [true, false]);
}

// ── 3. the controller, on that data, in ONE transaction that is rolled back ───────────────────────────────────────────────────
$noDirectBooking = static function (int $id) use ($one): bool {
    return in_array($one("SELECT COALESCE(direct_booking, 0) FROM bb_resource WHERE id = {$id}"), [0, '0', null, false], true);
};
$buildingsOf = static function (int $id) use ($all): array {
    return array_map('intval', array_column($all("SELECT building_id FROM bb_building_resource WHERE resource_id = {$id}"), 'building_id'));
};
$offeredFree = array_values(array_filter($members['offered'], $noDirectBooking));
if (count($offeredFree) < 3) {
    $cannotMeasure('fewer than 3 offered resources without direct booking: the controller arms need somewhere to move to');
}
$home = $offeredFree[0];
$homeBuildings = $buildingsOf($home);
$elsewhere = array_values(array_filter(array_slice($offeredFree, 1), static fn(int $id) => array_intersect($buildingsOf($id), $homeBuildings) === []));
if (!$elsewhere) {
    $cannotMeasure('every offered resource shares a building with the fixture\'s main: the «another building» arm has nowhere to go');
}
$sameBuilding = array_values(array_filter(array_slice($offeredFree, 1), static fn(int $id) => array_intersect($buildingsOf($id), $homeBuildings) !== []));

$fixtureId = -900001;
$accountId = (int)$one('SELECT created_by FROM bb_hospitality ORDER BY id LIMIT 1');
$request = static function (string $method, array $body) {
    return (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest($method, 'https://pe-api.test/booking/hospitality')->withParsedBody($body);
};
$controller = (new ReflectionClass(HospitalityController::class))->newInstanceWithoutConstructor();
$inject = static function (string $property, $value) use ($controller): void {
    (new ReflectionProperty(HospitalityController::class, $property))->setValue($controller, $value);
};
$inject('userSettings', ['account_id' => $accountId]);
$inject('acl', new class extends Acl {
    public function __construct()
    {
    }

    public function check($location, $required, $appname = '')
    {
        return true;
    }
});
$inject('repository', $repository);
$inject('orderRepository', new HospitalityOrderRepository());
$inject('directBookingGuard', new HospitalityDirectBookingGuard($db));

$put = static function (array $body) use ($controller, $request, $fixtureId): array {
    try {
        $response = $controller->update($request('PUT', $body), new Slim\Psr7\Response(), ['id' => (string)$fixtureId]);
        return [$response->getStatusCode(), json_decode((string)$response->getBody(), true) ?: []];
    } catch (Throwable $e) {
        return [0, ['threw' => get_class($e) . ': ' . $e->getMessage()]];
    }
};
$post = static function (array $body) use ($controller, $request): array {
    try {
        $response = $controller->store($request('POST', $body), new Slim\Psr7\Response());
        return [$response->getStatusCode(), json_decode((string)$response->getBody(), true) ?: []];
    } catch (Throwable $e) {
        return [0, ['threw' => get_class($e) . ': ' . $e->getMessage()]];
    }
};
$stored = static fn(): array => $all("SELECT resource_id, name, modified FROM bb_hospitality WHERE id = {$fixtureId}")[0] ?? [];
$placeAt = static function (int $resourceId) use ($db, $fixtureId): void {
    $db->exec("UPDATE bb_hospitality SET resource_id = {$resourceId}, name = 'pg-probe' WHERE id = {$fixtureId}");
};
$codeFor = ['inactive' => 'main_resource_inactive', 'no building' => 'main_resource_not_in_active_building', 'only inactive buildings' => 'main_resource_not_in_active_building'];

$countBefore = (int)$one('SELECT count(*) FROM bb_hospitality');
$db->beginTransaction();
try {
    $db->exec("INSERT INTO bb_hospitality (id, resource_id, name, created_by, modified_by) VALUES ({$fixtureId}, {$home}, 'pg-probe', {$accountId}, {$accountId})");

    // A move to a resource of each hidden class: 422, the right code, the row untouched.
    foreach ($codeFor as $class => $code) {
        foreach (array_slice($members[$class], 0, 4) as $target) {
            $placeAt($home);
            $before = $stored();
            [$status, $json] = $put(['resource_id' => $target]);
            $after = $stored();
            $expect("pg/update a move to resource {$target} ({$class}): 422 {$code}, naming it, the row unchanged",
                [$status, $json['code'] ?? ($json['threw'] ?? null), $json['resource_ids'] ?? null, $after === $before], [422, $code, [$target], true]);
        }
    }
    // A move to a resource the picker offers moves: in the fixture's own building, and in ANOTHER one (the building must not matter).
    foreach (['another building' => array_slice($elsewhere, 0, 3), 'the same building' => array_slice($sameBuilding, 0, 2)] as $label => $targets) {
        foreach (array_values(array_filter($targets, $noDirectBooking)) as $target) {
            $placeAt($home);
            [$status, $json] = $put(['resource_id' => $target]);
            $expect("pg/update a move to resource {$target} (offered, in {$label}): 200 and the row moved",
                [$status, (int)($stored()['resource_id'] ?? 0), $json['threw'] ?? null], [200, $target, null]);
        }
    }
    // The unchanged key: a main that has since gone inactive still saves a rename; leaving it for another inactive resource is refused.
    $staleMain = array_values(array_filter($members['inactive'], $noDirectBooking))[0] ?? null;
    if ($staleMain === null) {
        $cannotMeasure('no inactive resource without direct booking for the unchanged-key arm');
    }
    $placeAt($staleMain);
    [$status, $json] = $put(['resource_id' => $staleMain, 'name' => 'renamed']);
    $expect("pg/update the UNCHANGED key {$staleMain} of a main that is now inactive: 200 and the rename is stored",
        [$status, $stored()['name'] ?? null, (int)($stored()['resource_id'] ?? 0)], [200, 'renamed', $staleMain]);
    $placeAt($staleMain);
    [$status, $json] = $put(['resource_id' => (string)$staleMain, 'name' => 'renamed-again']);
    $expect("pg/update the UNCHANGED key as the string \"{$staleMain}\": 200", [$status, $stored()['name'] ?? null], [200, 'renamed-again']);
    $other = array_values(array_diff($members['inactive'], [$staleMain]))[0];
    $placeAt($staleMain);
    [$status, $json] = $put(['resource_id' => $other]);
    $expect("pg/update control: leaving that main for ANOTHER inactive resource {$other} is refused", [$status, $json['code'] ?? null, (int)($stored()['resource_id'] ?? 0)], [422, 'main_resource_inactive', $staleMain]);

    // store(): a hidden class is refused and nothing is inserted (an offered one is not POSTed here: the insert would advance the id sequence).
    $countInside = (int)$one('SELECT count(*) FROM bb_hospitality');
    foreach ($codeFor as $class => $code) {
        $target = $members[$class][0];
        [$status, $json] = $post(['resource_id' => $target, 'name' => 'pg-probe-new']);
        $expect("pg/store resource {$target} ({$class}): 422 {$code}, no row inserted",
            [$status, $json['code'] ?? ($json['threw'] ?? null), (int)$one('SELECT count(*) FROM bb_hospitality') === $countInside], [422, $code, true]);
    }
} finally {
    $db->rollBack();
}
$expect('rolled back: the fixture row is gone', (int)$one("SELECT count(*) FROM bb_hospitality WHERE id = {$fixtureId}"), 0);
$expect('rolled back: the number of hospitality rows is what it was', (int)$one('SELECT count(*) FROM bb_hospitality'), $countBefore);

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
echo "GREEN: all {$total} rows pass (" . count($classOf) . ' resources, ' . count($buildingIds) . ' active buildings, controller arms rolled back)' . "\n";
exit(0);
