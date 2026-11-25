<?php

/**
 * #1424 (Q6): the six admin hospitality writes drop the caches that carry has_hospitality, and a cache that cannot be reached never fails the write.
 *
 *   php tests/hospitality-marking/cache-invalidation-pg.php [--config=<header.inc.php>] [--domain=default]
 *
 * The REAL HospitalityController, the REAL repository (except create(), see below), the REAL direct-booking guard and the REAL order
 * repository, on PostgreSQL, inside ONE transaction that is rolled back. A fixture hospitality (explicit negative id) is written through
 * each of the six routes of /booking/hospitality:
 *   POST store · PUT update · DELETE destroy · POST remote-locations · DELETE remote-locations/{id} · PATCH remote-locations/{id}
 * and a recorder standing in for CacheService (the real one talks to Next.js and the WebSocket server) counts how often
 * invalidateHospitality() was asked for. Each stored write must ask exactly once; each refused or failed one (400, 404, 422) must not ask at all:
 * a cache is dropped for a change that happened, not for one that did not.
 *
 * Last, the recorder throws, first an Exception and then an Error (a TypeError is not an Exception): the write must still answer as it
 * did, because it is already committed.
 *
 * Only create() is replaced, to return the fixture's id: a real insert would advance the id sequence, which a rollback does not undo. What
 * create() does is not what is held here. CONTROLLER_FILE (optional) points at an alternative copy of HospitalityController, which
 * mutate-pg.py uses to show each deliberately broken copy turns this runner red.
 *
 * Refuses to measure when the connection is not PostgreSQL. The config is read, never written; credentials are never printed.
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
final class CacheHarnessWebSocketHelper
{
    public static function sendEntityNotificationAsync(string $entityType, $entityId, string $message, string $action, array $data = []): bool
    {
        return true;
    }

    public static function sendCacheInvalidation(array $queryKeys, ?array $debug = null): bool
    {
        return true;
    }
}
class_alias(CacheHarnessWebSocketHelper::class, 'App\modules\bookingfrontend\helpers\WebSocketHelper');

/** CacheService is replaced by a recorder (the real one makes HTTP requests to Next.js and WebSocket broadcasts). */
final class CacheHarnessCacheService
{
    public static int $calls = 0;
    public static ?string $throws = null; // 'exception' | 'error'

    public function invalidateHospitality(): bool
    {
        self::$calls++;
        if (self::$throws === 'exception') {
            throw new RuntimeException('the cache could not be reached');
        }
        if (self::$throws === 'error') {
            throw new TypeError('the cache layer returned the wrong type');
        }
        return true;
    }
}
class_alias(CacheHarnessCacheService::class, 'App\modules\bookingfrontend\services\CacheService');

require_once getenv('CONTROLLER_FILE') ?: $root . '/src/modules/booking/controllers/HospitalityController.php';

use App\Database\Db;
use App\modules\booking\controllers\HospitalityController;
use App\modules\booking\repositories\HospitalityOrderRepository;
use App\modules\booking\repositories\HospitalityRepository;
use App\modules\booking\services\HospitalityDirectBookingGuard;
use App\modules\phpgwapi\security\Acl;

if (!is_file($configPath)) {
    $cannotMeasure("no config file at {$configPath} (pass --config=<path to header.inc.php>)");
}
$settings = require $configPath;
$config = $settings['phpgw_domain'][$domain] ?? null;
if ($config === null || ($config['db_type'] ?? '') !== 'postgres') {
    $cannotMeasure("domain '{$domain}' is not a PostgreSQL domain in {$configPath}");
}
$db = Db::getInstance(Db::CreateDsn($config), $config['db_user'], $config['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (!$db->isConnected() || $db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
    $cannotMeasure('the connection did not open, or it is not pgsql: a result from it would be a false zero');
}

$rows = [];
$expect = static function (string $name, $actual, $expected) use (&$rows): void {
    $rows[] = [$name, $actual, $expected];
};
$expect('env/the controller that runs is CONTROLLER_FILE or this tree\'s',
    realpath((new ReflectionClass(HospitalityController::class))->getFileName()) === realpath(getenv('CONTROLLER_FILE') ?: $root . '/src/modules/booking/controllers/HospitalityController.php'), true);

$all = static function (string $sql) use ($db): array {
    $statement = $db->prepare($sql);
    $statement->execute();
    return $statement->fetchAll(PDO::FETCH_ASSOC);
};
$one = static function (string $sql) use ($all) {
    $rows = $all($sql);
    return $rows ? array_values($rows[0])[0] : null;
};

// Resources the picker offers (active, in an active building) and without direct booking: somewhere to put the fixture, and two locations.
$free = array_map('intval', array_column($all(
    'SELECT r.id FROM bb_resource r
      WHERE r.active = 1 AND COALESCE(r.direct_booking, 0) = 0
        AND EXISTS (SELECT 1 FROM bb_building_resource br JOIN bb_building b ON b.id = br.building_id WHERE br.resource_id = r.id AND b.active = 1)
      ORDER BY r.id LIMIT 3'
), 'id'));
if (count($free) < 3) {
    $cannotMeasure('fewer than 3 offered resources without direct booking: the fixture and its locations have nowhere to go');
}
[$home, $locationA, $locationB] = $free;
$inactive = (int)$one('SELECT id FROM bb_resource WHERE active <> 1 ORDER BY id LIMIT 1');
if ($inactive === 0) {
    $cannotMeasure('no inactive resource: the refused-update arm has nothing to move the fixture to');
}
$accountId = (int)$one('SELECT created_by FROM bb_hospitality ORDER BY id LIMIT 1');
$fixtureId = -900001;
$missingId = -900099;

$request = static fn(string $method, array $body = []) =>
    (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest($method, 'https://pe-api.test/booking/hospitality')->withParsedBody($body);
$repository = new class extends HospitalityRepository {
    public int $createdId = 0;

    /** Not a real insert: that would advance the id sequence, which a rollback does not undo. */
    public function create(array $data): int
    {
        return $this->createdId;
    }
};
$repository->createdId = $fixtureId;
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

/** Runs one route; returns [status, number of times the caches were asked to drop, what was thrown (null if nothing)]. */
$route = static function (callable $handler) use ($request): array {
    CacheHarnessCacheService::$calls = 0;
    try {
        $response = $handler();
        $status = $response->getStatusCode();
        $threw = null;
    } catch (Throwable $e) {
        $status = 0;
        $threw = get_class($e) . ': ' . $e->getMessage();
    }
    return [$status, CacheHarnessCacheService::$calls, $threw];
};
$locations =static fn(): array => $GLOBALS['all']("SELECT resource_id, active FROM bb_hospitality_remote_location WHERE hospitality_id = {$GLOBALS['fixtureId']} ORDER BY resource_id");
$res = static fn() => new Slim\Psr7\Response();

$countBefore = (int)$one('SELECT count(*) FROM bb_hospitality');
$db->beginTransaction();
try {
    $db->exec("INSERT INTO bb_hospitality (id, resource_id, name, created_by, modified_by) VALUES ({$fixtureId}, {$home}, 'cache-probe', {$accountId}, {$accountId})");

    // ── update (PUT) ──
    $expect('update/PUT a rename: 200 and the caches are dropped once',
        $route(fn() => $controller->update($request('PUT', ['name' => 'cache-probe-2']), $res(), ['id' => (string)$fixtureId])), [200, 1, null]);
    $expect('update/PUT a move to an inactive resource is refused (422): the caches are NOT dropped',
        $route(fn() => $controller->update($request('PUT', ['resource_id' => $inactive]), $res(), ['id' => (string)$fixtureId])), [422, 0, null]);
    $expect('update/PUT on a hospitality that does not exist (404): the caches are NOT dropped',
        $route(fn() => $controller->update($request('PUT', ['name' => 'x']), $res(), ['id' => (string)$missingId])), [404, 0, null]);

    // ── store (POST) ──
    $expect('store/POST a hospitality on an offered resource: 201 and the caches are dropped once',
        $route(fn() => $controller->store($request('POST', ['resource_id' => $home, 'name' => 'cache-probe-new']), $res())), [201, 1, null]);
    $expect('store/POST without a name (400): the caches are NOT dropped',
        $route(fn() => $controller->store($request('POST', ['resource_id' => $home]), $res())), [400, 0, null]);
    $expect('store/POST on an inactive resource is refused (422): the caches are NOT dropped',
        $route(fn() => $controller->store($request('POST', ['resource_id' => $inactive, 'name' => 'cache-probe-new']), $res())), [422, 0, null]);

    // ── remote locations: add, toggle (off and on), remove ──
    $expect('add-location/POST a delivery location: 201, the row is there, the caches are dropped once',
        [$route(fn() => $controller->addRemoteLocation($request('POST', ['resource_id' => $locationA]), $res(), ['id' => (string)$fixtureId])), array_column($locations(), 'resource_id')],
        [[201, 1, null], [$locationA]]);
    $expect('add-location/POST without resource_id (400): the caches are NOT dropped',
        $route(fn() => $controller->addRemoteLocation($request('POST', []), $res(), ['id' => (string)$fixtureId])), [400, 0, null]);
    $expect('toggle-location/PATCH active=false: 200, the location is off, the caches are dropped once',
        [$route(fn() => $controller->toggleRemoteLocation($request('PATCH', ['active' => false]), $res(), ['id' => (string)$fixtureId, 'resourceId' => (string)$locationA])), array_map('intval', array_column($locations(), 'active'))],
        [[200, 1, null], [0]]);
    $expect('toggle-location/PATCH active=true: 200, the location is on again, the caches are dropped once',
        [$route(fn() => $controller->toggleRemoteLocation($request('PATCH', ['active' => true]), $res(), ['id' => (string)$fixtureId, 'resourceId' => (string)$locationA])), array_map('intval', array_column($locations(), 'active'))],
        [[200, 1, null], [1]]);
    $expect('toggle-location/PATCH without active (400): the caches are NOT dropped',
        $route(fn() => $controller->toggleRemoteLocation($request('PATCH', []), $res(), ['id' => (string)$fixtureId, 'resourceId' => (string)$locationA])), [400, 0, null]);
    $expect('remove-location/DELETE the location: 200, the row is gone, the caches are dropped once',
        [$route(fn() => $controller->removeRemoteLocation($request('DELETE'), $res(), ['id' => (string)$fixtureId, 'resourceId' => (string)$locationA])), array_column($locations(), 'resource_id')],
        [[200, 1, null], []]);

    // ── a cache that cannot be reached never fails the write ──
    CacheHarnessCacheService::$throws = 'exception';
    $expect('resilience/the cache throws an Exception: the update still answers 200, and the cache was asked',
        $route(fn() => $controller->update($request('PUT', ['name' => 'cache-probe-3']), $res(), ['id' => (string)$fixtureId])), [200, 1, null]);
    CacheHarnessCacheService::$throws = 'error';
    $expect('resilience/the cache throws an Error (a TypeError is not an Exception): the update still answers 200, and the cache was asked',
        $route(fn() => $controller->update($request('PUT', ['name' => 'cache-probe-4']), $res(), ['id' => (string)$fixtureId])), [200, 1, null]);
    $expect('resilience/...and the write is stored (the rename of that update)', $one("SELECT name FROM bb_hospitality WHERE id = {$fixtureId}"), 'cache-probe-4');
    $expect('resilience/the cache throws an Error: a DELETE of a location still answers 200 (add one first, then remove it)',
        [$route(fn() => $controller->addRemoteLocation($request('POST', ['resource_id' => $locationB]), $res(), ['id' => (string)$fixtureId]))[0],
         $route(fn() => $controller->removeRemoteLocation($request('DELETE'), $res(), ['id' => (string)$fixtureId, 'resourceId' => (string)$locationB]))],
        [201, [200, 1, null]]);
    CacheHarnessCacheService::$throws = null;

    // ── destroy (DELETE) last: it takes the fixture with it ──
    $expect('destroy/DELETE a hospitality that does not exist (404): the caches are NOT dropped',
        $route(fn() => $controller->destroy($request('DELETE'), $res(), ['id' => (string)$missingId])), [404, 0, null]);
    $expect('destroy/the fixture is active before the DELETE', (int)$one("SELECT active FROM bb_hospitality WHERE id = {$fixtureId}"), 1);
    $expect('destroy/DELETE the fixture: 200, the hospitality is switched off (delete() is a soft delete: active = 0), the caches are dropped once',
        [$route(fn() => $controller->destroy($request('DELETE'), $res(), ['id' => (string)$fixtureId])), (int)$one("SELECT active FROM bb_hospitality WHERE id = {$fixtureId}")], [[200, 1, null], 0]);
} finally {
    $db->rollBack();
}
$expect('rolled back: the number of hospitality rows is what it was', (int)$one('SELECT count(*) FROM bb_hospitality'), $countBefore);
$expect('rolled back: no remote location of the fixture is left', (int)$one("SELECT count(*) FROM bb_hospitality_remote_location WHERE hospitality_id = {$fixtureId}"), 0);

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
echo "GREEN: all {$total} rows pass (six write routes, refusals and a throwing cache; rolled back)\n";
exit(0);
