<?php

/**
 * Hermetic tests for what the applicant's catering endpoints SEND and what they SURVIVE
 * (GH #1423 follow-ups, task 34384).
 *
 *   php tests/hospitality-deadline/run_citizen.php
 *
 * Covers, with no database, no mail transport and no HTTP server:
 *   (5) no officer data in ANY bookingfrontend catering response. The real controllers run with the
 *       collaborators that touch the database stubbed; the REAL getOrderWithLines() and the REAL
 *       HospitalityOrder::serialize() run on top of stubbed primitives that plant an officer's changelog
 *       rows, so the instrument can see the leak: the admin controller's show() returns it, the
 *       applicant's GET / POST / PUT / DELETE must not. The routes are read from the REAL Routes.php,
 *       so a new bookingfrontend hospitality route without a driver fails red. The keys an order may
 *       carry are parsed from the client's TypeScript types (0 keys parsed = red).
 *   (6) a throw AFTER the committed write must not turn into a 500: the re-read of the order in
 *       deleteOrder, createOrder and updateOrder, with a RuntimeException, a PDOException and a TypeError.
 *       A failing write and a failing pre-write read must still say so.
 *
 * Mail is never sent (the notifier is a recording stub) and storage/emails is never touched.
 *
 * Environment (all optional): HD_ROOT = tree holding src/ (default: this repo);
 * VENDOR_AUTOLOAD = composer autoload with Slim (default: HD_ROOT/vendor/autoload.php).
 *
 * Exit code: 0 all pass, 1 any failure.
 */

date_default_timezone_set('Europe/Oslo');
error_reporting(E_ALL & ~E_DEPRECATED);

$root = getenv('HD_ROOT') ?: dirname(__DIR__, 2);
require_once getenv('VENDOR_AUTOLOAD') ?: $root . '/vendor/autoload.php';

// The stubs' parents (ApplicationService, UserHelper, ApplicationHelper) are not under test, but loading them
// reaches the legacy includes through these two constants. They point at THIS repo, never at HD_ROOT: the
// mutation driver's temp tree holds only the files the harness reads.
define('PHPGW_SERVER_ROOT', dirname(__DIR__, 2) . '/src/modules');
define('SRC_ROOT_PATH', dirname(__DIR__, 2) . '/src');

foreach ([
    'traits/SerializableTrait.php',
    'helpers/ResponseHelper.php',
    'modules/booking/models/HospitalityOrder.php',
    'modules/booking/models/HospitalityOrderLine.php',
    'modules/booking/services/HospitalityDeadlineCalculator.php',
    'modules/booking/services/HospitalityOrderGuard.php',
    'modules/booking/services/HospitalityOrderDiff.php',
    'modules/booking/services/CateringText.php',
    'modules/booking/services/CateringLangSwitch.php',
    'modules/booking/services/CateringMailRenderer.php',
    'modules/booking/services/TranslationLangSwitch.php',
    'modules/booking/repositories/HospitalityOrderRepository.php',
    'modules/bookingfrontend/services/applications/CateringChangeNotifier.php',
    'modules/bookingfrontend/services/applications/CitizenHospitalityOrder.php',
    'modules/bookingfrontend/controllers/applications/HospitalityOrderController.php',
    'modules/booking/controllers/HospitalityOrderController.php',
] as $file) {
    require_once $root . '/src/' . $file;
}

use App\modules\booking\controllers\HospitalityOrderController as AdminOrderController;
use App\modules\booking\repositories\HospitalityArticleRepository;
use App\modules\booking\repositories\HospitalityOrderRepository;
use App\modules\booking\repositories\HospitalityRepository;
use App\modules\booking\services\HospitalityOrderGuard;
use App\modules\bookingfrontend\controllers\applications\HospitalityOrderController as CitizenOrderController;
use App\modules\bookingfrontend\helpers\ApplicationHelper;
use App\modules\bookingfrontend\helpers\UserHelper;
use App\modules\bookingfrontend\services\applications\ApplicationService;
use App\modules\bookingfrontend\services\applications\CateringChangeNotifier;
use App\modules\bookingfrontend\services\applications\CitizenHospitalityOrder;
use Psr\Http\Message\ServerRequestInterface;

// The global lang() the controller reaches for when it refuses a request; the text is irrelevant here.
if (!function_exists('lang')) {
    function lang($key, ...$args)
    {
        return "!{$key}";
    }
}

// --- tiny assertion kit (same shape as run_mail.php) ---------------------------------------------------
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
    $check($name, str_contains($haystack, $needle), "missing «{$needle}» in " . substr($haystack, 0, 300));
};
$lacks = static function (string $name, string $haystack, string $needle) use ($check): void {
    $check($name, !str_contains($haystack, $needle), "unexpected «{$needle}» in " . substr($haystack, 0, 300));
};

$logFile = tempnam(sys_get_temp_dir(), 'hd-citizen-log-');
ini_set('error_log', $logFile);
ini_set('log_errors', '1');
$logTail = static fn(): string => (string)file_get_contents($logFile);
$clearLog = static function () use ($logFile): void {
    file_put_contents($logFile, '');
};

// =======================================================================================================
// The stubs. Everything that would touch the database or the session is replaced; the code under test
// (the controllers, CitizenHospitalityOrder, the REAL getOrderWithLines / getOrdersWithLinesByApplication
// of the repository, the REAL model serialisation and the REAL guard) is not.
// =======================================================================================================
const OFFICER_LID = 'OFFICER_LID_MARK';
const INTERNAL_NOTE = 'INTERNAL_NOTE_MARK';

final class CitizenStubRepo extends HospitalityOrderRepository
{
    /** @var array<int, array> */
    public array $orders = [];
    /** @var array<int, list<array>> */
    public array $lines = [];
    /** @var list<array> what getChangelog() returns for ANY order: an officer's row and the applicant's */
    public array $changelog = [];
    /** @var list<string> */
    public array $calls = [];
    /** @var list<array> */
    public array $entries = [];
    public bool $wrote = false;
    /** thrown by getOrderLines() once something has been written: the re-read of a committed write */
    public ?\Throwable $readThrowsAfterWrite = null;
    /** thrown by getOrderLines() before anything is written: the `$before` snapshot */
    public ?\Throwable $beforeThrows = null;
    /** thrown by updateStatus(): the write itself */
    public ?\Throwable $writeThrows = null;
    private int $nextOrderId = 100;
    private int $nextLineId = 500;

    public function __construct()
    {
    }

    public function getById(int $id): ?array
    {
        return $this->orders[$id] ?? null;
    }

    public function getByApplicationId(int $applicationId): array
    {
        return array_values(array_filter($this->orders, static fn(array $o) => (int)$o['application_id'] === $applicationId));
    }

    public function getOrderLines(int $orderId): array
    {
        if ($this->wrote && $this->readThrowsAfterWrite) {
            throw $this->readThrowsAfterWrite;
        }
        if (!$this->wrote && $this->beforeThrows) {
            throw $this->beforeThrows;
        }
        return $this->lines[$orderId] ?? [];
    }

    public function calculateOrderTotal(int $orderId): float
    {
        return array_sum(array_map(static fn(array $l) => (float)$l['amount'], $this->lines[$orderId] ?? []));
    }

    public function getChangelog(int $orderId): array
    {
        $this->calls[] = 'getChangelog';
        return $this->changelog;
    }

    public function create(array $data): int
    {
        $id = $this->nextOrderId++;
        $this->orders[$id] = [
            'id' => $id,
            'application_id' => $data['application_id'],
            'hospitality_id' => $data['hospitality_id'],
            'location_resource_id' => $data['location_resource_id'],
            'status' => $data['status'],
            'comment' => $data['comment'] ?? null,
            'special_requirements' => $data['special_requirements'] ?? null,
            'serving_time_iso' => self::toStorageTimestamp($data['serving_time_iso'] ?? null),
            'created' => '2026-10-28 09:00:00',
            'modified' => '2026-10-28 09:00:00',
            'created_by' => $data['created_by'] ?? null,
            'modified_by' => $data['created_by'] ?? null,
            'billed' => 0,
            'hospitality_name' => 'Kjøkken',
            'location_name' => 'Sal A',
        ];
        $this->lines[$id] = [];
        $this->wrote = true;
        $this->calls[] = 'create';
        return $id;
    }

    public function update(int $id, array $data): bool
    {
        $this->orders[$id] = array_replace($this->orders[$id], $data);
        $this->wrote = true;
        $this->calls[] = 'update';
        return true;
    }

    public function updateStatus(int $id, string $status, ?int $modifiedBy = null): bool
    {
        if ($this->writeThrows) {
            throw $this->writeThrows;
        }
        $this->orders[$id]['status'] = $status;
        $this->wrote = true;
        $this->calls[] = "updateStatus:{$status}";
        return true;
    }

    public function addOrderLine(array $data): int
    {
        $id = $this->nextLineId++;
        $this->lines[(int)$data['order_id']][] = [
            'id' => $id,
            'order_id' => $data['order_id'],
            'hospitality_article_id' => $data['hospitality_article_id'],
            'quantity' => number_format((float)$data['quantity'], 2, '.', ''),
            'unit_price' => number_format((float)$data['unit_price'], 2, '.', ''),
            'tax_code' => $data['tax_code'],
            'amount' => number_format((float)$data['quantity'] * (float)$data['unit_price'], 2, '.', ''),
            'comment' => $data['comment'] ?? null,
            'article_name' => 'Kaffe',
            'unit' => 'stk',
        ];
        $this->wrote = true;
        return $id;
    }

    public function deleteOrderLine(int $lineId): bool
    {
        foreach ($this->lines as $orderId => $rows) {
            $this->lines[$orderId] = array_values(array_filter($rows, static fn(array $l) => (int)$l['id'] !== $lineId));
        }
        $this->wrote = true;
        return true;
    }

    public function addChangelogEntry(array $data): int
    {
        $this->entries[] = $data;
        return count($this->entries);
    }

    public function isPaidAtCheckout(int $applicationId, int $hospitalityId): bool
    {
        return false;
    }

    public function getEarliestApplicationDate(int $applicationId): ?string
    {
        return gmdate('Y-m-d H:i:s', strtotime('+30 days'));
    }
}

final class StubApplicationService extends ApplicationService
{
    public array $application = [];

    public function __construct()
    {
    }

    public function getApplicationById(int $id): ?array
    {
        return (int)$this->application['id'] === $id ? $this->application : null;
    }
}

final class StubApplicationHelper extends ApplicationHelper
{
    public function __construct()
    {
    }

    public function canViewApplication(array $application, ServerRequestInterface $request): bool
    {
        return true;
    }

    public function canModifyApplication(array $application, ServerRequestInterface $request): bool
    {
        return true;
    }
}

final class StubUserHelper extends UserHelper
{
    public function __construct()
    {
    }

    public function get_user_id($ssn)
    {
        return 77;
    }

    public function is_logged_in()
    {
        return false;
    }

    public function get_all_delegates($ssn)
    {
        return [];
    }
}

final class StubHospitalityRepo extends HospitalityRepository
{
    public function __construct()
    {
    }

    public function getById(int $id): ?array
    {
        return [
            'id' => $id, 'active' => 1, 'name' => 'Kjøkken', 'description' => null, 'open_days' => 127,
            'order_by_time_value' => null, 'order_by_time_unit' => null,
            'resource_cancellation_deadline_value' => null, 'resource_cancellation_deadline_unit' => null,
        ];
    }

    public function getDeliveryLocations(int $hospitalityId): array
    {
        return [['id' => 5, 'name' => 'Sal A']];
    }

    public function getContact(int $hospitalityId, int $fallbackBuildingId = 0): array
    {
        return ['contact_info' => 'Ring kjøkkenet', 'building_name' => 'Bygg A', 'building_email' => 'a@example.test', 'building_phone' => '12345678'];
    }
}

final class StubArticleRepo extends HospitalityArticleRepository
{
    public function __construct()
    {
    }

    public function resolveEffectivePricing(int $articleId): ?array
    {
        return ['effective_price' => 45.0, 'effective_tax_code' => 1];
    }

    public function getArticleById(int $id): ?array
    {
        return ['id' => $id, 'hospitality_id' => 3, 'active' => 1];
    }
}

final class RecordingNotifier extends CateringChangeNotifier
{
    /** @var list<array> */
    public array $payloads = [];

    public function __construct()
    {
    }

    public function notify(array $payload): bool
    {
        $this->payloads[] = $payload;
        return true;
    }
}

// --- fixtures ------------------------------------------------------------------------------------------
$servingUtc = gmdate('Y-m-d H:i:s', strtotime('+30 days'));
$application = [
    'id' => 84091, 'status' => 'ACCEPTED', 'id_string' => '84091', 'name' => 'Sommerfest',
    'building_id' => 1, 'building_name' => 'Bygg A', 'customer_organization_name' => null,
    'case_officer_id' => 5, 'activity_id' => null,
];
$newRepo = static function () use ($servingUtc): CitizenStubRepo {
    $repo = new CitizenStubRepo();
    foreach ([7 => 'pending', 8 => 'cancelled'] as $id => $status) {
        $repo->orders[$id] = [
            'id' => $id, 'application_id' => 84091, 'hospitality_id' => 3, 'location_resource_id' => 5,
            'status' => $status, 'comment' => 'Uten nøtter', 'special_requirements' => null,
            'serving_time_iso' => $servingUtc, 'created' => '2026-10-20 10:00:00', 'modified' => '2026-10-21 11:00:00',
            'created_by' => 4242, 'modified_by' => 4243, 'billed' => 0,
            'hospitality_name' => 'Kjøkken', 'location_name' => 'Sal A',
        ];
        $repo->lines[$id] = [[
            'id' => 400 + $id, 'order_id' => $id, 'hospitality_article_id' => 11, 'quantity' => '3.00',
            'unit_price' => '45.00', 'tax_code' => 1, 'amount' => '135.00', 'comment' => null,
            'article_name' => 'Kaffe', 'unit' => 'stk',
        ]];
    }
    // An officer's entry (account_lid + an internal comment) and the applicant's own, as getChangelog() shapes them.
    $repo->changelog = [
        [
            'id' => 2, 'order_id' => 7, 'case_officer_id' => 5, 'booking_user_id' => null, 'change_type' => 'updated',
            'old_value' => null, 'new_value' => ['comment' => 'x'], 'comment' => INTERNAL_NOTE,
            'changed_at' => '2026-10-21T12:00:00+02:00', 'case_officer_name' => OFFICER_LID, 'booking_user_name' => null,
        ],
        [
            'id' => 1, 'order_id' => 7, 'case_officer_id' => null, 'booking_user_id' => 77, 'change_type' => 'applicant_change',
            'old_value' => null, 'new_value' => null, 'comment' => 'Order changed by applicant via Min side',
            'changed_at' => '2026-10-20T10:00:00+02:00', 'case_officer_name' => null, 'booking_user_name' => 'Kari Nordmann',
        ],
    ];
    return $repo;
};

$citizenRef = new \ReflectionClass(CitizenOrderController::class);
$makeCitizen = static function (CitizenStubRepo $repo, RecordingNotifier $notifier, array $app) use ($citizenRef): object {
    $service = new StubApplicationService();
    $service->application = $app;
    $user = new StubUserHelper();
    $user->ssn = '01010112345';
    $c = $citizenRef->newInstanceWithoutConstructor();
    foreach ([
        'applicationHelper' => new StubApplicationHelper(),
        'applicationService' => $service,
        'userHelper' => $user,
        'hospitalityRepo' => new StubHospitalityRepo(),
        'articleRepo' => new StubArticleRepo(),
        'orderRepo' => $repo,
        'guard' => new HospitalityOrderGuard(),
        'notifier' => $notifier,
    ] as $prop => $value) {
        $citizenRef->getProperty($prop)->setValue($c, $value);
    }
    return $c;
};

/** @return array{status: ?int, body: string, threw: ?\Throwable} */
$invoke = static function (object $controller, string $handler, string $verb, array $args, string $json = ''): array {
    try {
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest($verb, 'https://pe-api.test/bookingfrontend/x');
        if ($json !== '') {
            $request = $request->withBody((new \Slim\Psr7\Factory\StreamFactory())->createStream($json));
        }
        $response = $controller->$handler($request->withHeader('Content-Type', 'application/json'), new \Slim\Psr7\Response(), $args);
        return ['status' => $response->getStatusCode(), 'body' => (string)$response->getBody(), 'threw' => null];
    } catch (\Throwable $e) {
        return ['status' => null, 'body' => '', 'threw' => $e];
    }
};

$walkKeys = static function (mixed $value, string $needle) use (&$walkKeys): bool {
    if (!is_array($value)) {
        return false;
    }
    foreach ($value as $k => $v) {
        if ($k === $needle || $walkKeys($v, $needle)) {
            return true;
        }
    }
    return false;
};

// =======================================================================================================
// 1. The client's contract, parsed from the TypeScript types (0 keys parsed = red)
// =======================================================================================================
$tsPath = $root . '/src/modules/bookingfrontend/client/src/service/types/api/hospitality.types.ts';
$tsSource = is_file($tsPath) ? (string)file_get_contents($tsPath) : '';
$parseInterface = static function (string $source, string $name): array {
    $out = ['required' => [], 'optional' => []];
    if (!preg_match('/^export interface ' . preg_quote($name, '/') . ' \{(.*?)^\}/ms', $source, $m)) {
        return $out;
    }
    foreach (preg_split('/\R/', $m[1]) as $line) {
        if (preg_match('/^ {4}([a-z_]+)(\?)?:/', $line, $k)) {
            $out[($k[2] ?? '') === '?' ? 'optional' : 'required'][] = $k[1];
        }
    }
    return $out;
};
$tsOrder = $parseInterface($tsSource, 'IHospitalityOrder');
$tsLine = $parseInterface($tsSource, 'IHospitalityOrderLine');
$check('TS contract: IHospitalityOrder was parsed (a parse of 0 keys must not read as green)', count($tsOrder['required']) > 0, 'required=' . count($tsOrder['required']));
$check('TS contract: IHospitalityOrderLine was parsed', count($tsLine['required']) > 0, 'required=' . count($tsLine['required']));
$sortedCopy = static function (array $a): array {
    sort($a);
    return $a;
};
$same('TS contract: the order keys every endpoint sends are exactly the type\'s required keys', $sortedCopy(CitizenHospitalityOrder::ORDER_KEYS), $sortedCopy($tsOrder['required']));
$same('TS contract: the keys only GET sends are exactly the type\'s optional keys', $sortedCopy(CitizenHospitalityOrder::READ_KEYS), $sortedCopy($tsOrder['optional']));
$same('TS contract: the line keys are exactly the line type\'s keys', $sortedCopy(CitizenHospitalityOrder::LINE_KEYS), $sortedCopy($tsLine['required']));
$tsAllKeys = array_merge($tsOrder['required'], $tsOrder['optional']);
foreach (['changelog', 'created', 'modified', 'created_by', 'modified_by', 'billed'] as $officerKey) {
    $check("TS contract: the type does not declare «{$officerKey}»", !in_array($officerKey, $tsAllKeys, true));
}

// =======================================================================================================
// 2. CitizenHospitalityOrder::present() on its own
// =======================================================================================================
$serialized = [
    'id' => 7, 'application_id' => 84091, 'hospitality_id' => 3, 'location_resource_id' => 5, 'status' => 'pending',
    'comment' => 'c', 'special_requirements' => 's', 'serving_time_iso' => '2026-11-27T12:00:00+01:00',
    'hospitality_name' => 'Kjøkken', 'location_name' => 'Sal A', 'total_amount' => 135.0,
    'created' => 'x', 'modified' => 'x', 'created_by' => 1, 'modified_by' => 2, 'changelog' => [['case_officer_name' => OFFICER_LID]],
    'billed' => 1, 'a_field_added_next_year' => 'x',
    'lines' => [['id' => 1, 'order_id' => 7, 'hospitality_article_id' => 11, 'quantity' => '3.00', 'unit_price' => '45.00',
        'tax_code' => 1, 'amount' => '135.00', 'comment' => null, 'article_name' => 'Kaffe', 'unit' => 'stk',
        'internal_cost' => '12.00', 'supplier' => 'x']],
];
$presented = CitizenHospitalityOrder::present($serialized, ['actions' => ['change' => []], 'contact' => ['info' => 'x'], 'last_applicant_change_at' => 'x', 'case_officer_id' => 5]);
$same('present: only the allowed order keys survive (a field added next year does not)', $sortedCopy(array_keys($presented)), $sortedCopy(array_merge(CitizenHospitalityOrder::ORDER_KEYS, ['actions', 'contact'])));
$same('present: only the allowed line keys survive', $sortedCopy(array_keys($presented['lines'][0])), $sortedCopy(CitizenHospitalityOrder::LINE_KEYS));
$check('present: no changelog at any depth', !$walkKeys($presented, 'changelog'));
$lacks('present: no officer mark in the output', (string)json_encode($presented), OFFICER_LID);
$same('present: values of kept keys are untouched', [$presented['id'], $presented['status'], $presented['lines'][0]['article_name']], [7, 'pending', 'Kaffe']);
$same('present: an order with no lines gets lines = []', CitizenHospitalityOrder::present(['id' => 1])['lines'], []);
$core = CitizenHospitalityOrder::present($serialized);
$same('present: it is idempotent', CitizenHospitalityOrder::present($core), $core);
$same('present: `actions` and `contact` ride along only when the caller hands them over', array_keys(array_diff_key($presented, $core)), ['actions', 'contact']);
$check('control: the same walk DOES find a changelog in the unfiltered input', $walkKeys($serialized, 'changelog'));

// =======================================================================================================
// 3. The routes: read from the REAL Routes.php. A route without a driver fails red.
// =======================================================================================================
$routesPath = $root . '/src/modules/bookingfrontend/routes/Routes.php';
$routesSource = is_file($routesPath) ? (string)file_get_contents($routesPath) : '';
$check('Routes.php imports the bookingfrontend HospitalityOrderController (the booking module has one of the same name)',
    str_contains($routesSource, 'use App\\modules\\bookingfrontend\\controllers\\applications\\HospitalityOrderController;'));
preg_match_all(
    '/->(get|post|put|delete|patch)\(\s*\'([^\']+)\'\s*,\s*(?:HospitalityOrderController::class\s*\.\s*\':(\w+)\'|\[\s*HospitalityOrderController::class\s*,\s*\'(\w+)\'\s*\])/',
    $routesSource,
    $matches,
    PREG_SET_ORDER
);
$routes = array_map(static fn(array $m) => ['verb' => strtoupper($m[1]), 'path' => $m[2], 'handler' => ($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? '')], $matches);
$check('control: the route parser finds the hospitality routes at all', count($routes) >= 4, 'found ' . count($routes));
$same('control: ...and the six that exist today',
    $sortedCopy(array_map(static fn(array $r) => $r['verb'] . ' ' . $r['handler'], $routes)),
    $sortedCopy(['GET getAvailableHospitalities', 'GET getOrders', 'POST createOrder', 'PUT updateOrder', 'DELETE deleteOrder', 'GET getMenu'])
);

const DRIVEN = ['getOrders' => 'GET', 'createOrder' => 'POST', 'updateOrder' => 'PUT', 'deleteOrder' => 'DELETE'];
const ORDER_FREE = ['getAvailableHospitalities' => 'GET', 'getMenu' => 'GET'];
$orderTokens = '/new HospitalityOrder\(|getOrderWithLines|getOrdersWithLinesByApplication|->orderRepo|changelog/i';
$methodSource = static function (string $class, string $method): string {
    $rm = new \ReflectionMethod($class, $method);
    return implode('', array_slice(file((string)$rm->getFileName()), $rm->getStartLine() - 1, $rm->getEndLine() - $rm->getStartLine() + 1));
};
$check('control: the order-token scan DOES flag createOrder', preg_match($orderTokens, $methodSource(CitizenOrderController::class, 'createOrder')) === 1);
foreach ($routes as $r) {
    $label = "{$r['verb']} {$r['path']} -> {$r['handler']}";
    if (isset(DRIVEN[$r['handler']])) {
        $same("route {$label}: driven below, with the verb the driver expects", $r['verb'], DRIVEN[$r['handler']]);
    } elseif (isset(ORDER_FREE[$r['handler']])) {
        $same("route {$label}: order-free, with the verb expected", $r['verb'], ORDER_FREE[$r['handler']]);
        $check("route {$label}: ...and its body touches no order (static scan)", preg_match($orderTokens, $methodSource(CitizenOrderController::class, $r['handler'])) === 0);
    } else {
        $check("route {$label}: HAS NO DRIVER in run_citizen.php (add one, or prove the handler order-free)", false);
    }
}
foreach (array_keys(DRIVEN) as $handler) {
    $check("control: the driven handler {$handler} is routed", in_array($handler, array_column($routes, 'handler'), true));
}

// =======================================================================================================
// 4. (5) What the applicant is sent. Driven through the real controller; a planted officer row must not appear.
// =======================================================================================================
$assertCitizenOrder = static function (string $name, array $order) use ($check, $walkKeys, $tsAllKeys): void {
    $extra = array_values(array_diff(array_keys($order), $tsAllKeys));
    $check("{$name}: every key is one the client's IHospitalityOrder declares", $extra === [], 'not declared: ' . implode(',', $extra));
    $check("{$name}: no changelog key at any depth", !$walkKeys($order, 'changelog'));
    $check("{$name}: no created / modified / created_by / modified_by",
        array_intersect(['created', 'modified', 'created_by', 'modified_by'], array_keys($order)) === []);
};
$assertCitizenBody = static function (string $name, string $body) use ($lacks): void {
    $lacks("{$name}: the officer's account_lid is not in the body", $body, OFFICER_LID);
    $lacks("{$name}: the officer's internal comment is not in the body", $body, INTERNAL_NOTE);
    $lacks("{$name}: no case_officer_* field in the body", $body, 'case_officer');
    $lacks("{$name}: no booking_user_name in the body", $body, 'booking_user_name');
};

// The control that makes every absence below mean something: the ADMIN controller, on the same stub, DOES
// return the planted rows. (The real show() and the real getOrderWithLines + serialize.)
$adminRef = new \ReflectionClass(AdminOrderController::class);
$adminRepo = $newRepo();
$admin = $adminRef->newInstanceWithoutConstructor();
$adminRef->getProperty('repository')->setValue($admin, $adminRepo);
$adminResponse = $admin->show(
    (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', 'https://pe-api.test/booking/hospitality-orders/7'),
    new \Slim\Psr7\Response(),
    ['id' => 7]
);
$adminBody = (string)$adminResponse->getBody();
$adminJson = json_decode($adminBody, true) ?: [];
$same('admin control: show() answers 200', $adminResponse->getStatusCode(), 200);
$contains('admin control: the officer\'s account_lid IS in the admin response', $adminBody, OFFICER_LID);
$contains('admin control: the internal comment IS in the admin response', $adminBody, INTERNAL_NOTE);
$same('admin control: the admin response carries both changelog entries', count($adminJson['changelog'] ?? []), 2);
$check('admin control: ...and created_by / modified_by', isset($adminJson['created_by'], $adminJson['modified_by']));

// GET /applications/{id}/hospitality-orders
$repo = $newRepo();
$notifier = new RecordingNotifier();
$get = $invoke($makeCitizen($repo, $notifier, $application), 'getOrders', 'GET', ['id' => 84091]);
$same('GET hospitality-orders: no exception', $get['threw']?->getMessage(), null);
$same('GET hospitality-orders: 200', $get['status'], 200);
$list = json_decode($get['body'], true);
$check('GET hospitality-orders: a list of two orders', is_array($list) && count($list) === 2, substr($get['body'], 0, 200));
$assertCitizenBody('GET hospitality-orders', $get['body']);
foreach ((array)$list as $i => $item) {
    $assertCitizenOrder("GET hospitality-orders [{$i}]", (array)$item);
    $check("GET hospitality-orders [{$i}]: still carries lines, actions and contact (the client reads them)", isset($item['lines'], $item['actions'], $item['contact']));
}

// POST /applications/{id}/hospitality-orders
$repo = $newRepo();
$notifier = new RecordingNotifier();
$post = $invoke(
    $makeCitizen($repo, $notifier, $application), 'createOrder', 'POST', ['id' => 84091],
    json_encode(['hospitality_id' => 3, 'location_resource_id' => 5, 'serving_time_iso' => gmdate('c', strtotime('+30 days')), 'lines' => [['hospitality_article_id' => 11, 'quantity' => 2]]])
);
$same('POST hospitality-orders: no exception', $post['threw']?->getMessage(), null);
$same('POST hospitality-orders: 201', $post['status'], 201);
$order = json_decode($post['body'], true);
$assertCitizenBody('POST hospitality-orders', $post['body']);
$assertCitizenOrder('POST hospitality-orders', (array)$order);
$check('POST hospitality-orders: the order comes back with its line', isset($order['id'], $order['lines'][0]['article_name']), substr($post['body'], 0, 200));
$same('POST hospitality-orders: the applicant\'s change is still recorded (one case entry, one mail)', [count($repo->entries), count($notifier->payloads)], [1, 1]);

// PUT /applications/{id}/hospitality-orders/{orderId}
$repo = $newRepo();
$notifier = new RecordingNotifier();
$put = $invoke($makeCitizen($repo, $notifier, $application), 'updateOrder', 'PUT', ['id' => 84091, 'orderId' => 7], json_encode(['comment' => 'Ny kommentar']));
$same('PUT hospitality-orders/{id}: no exception', $put['threw']?->getMessage(), null);
$same('PUT hospitality-orders/{id}: 200', $put['status'], 200);
$order = json_decode($put['body'], true);
$assertCitizenBody('PUT hospitality-orders/{id}', $put['body']);
$assertCitizenOrder('PUT hospitality-orders/{id}', (array)$order);
$same('PUT hospitality-orders/{id}: the change is in the response', $order['comment'] ?? null, 'Ny kommentar');
$same('PUT hospitality-orders/{id}: the applicant\'s change is still recorded (one case entry, one mail)', [count($repo->entries), count($notifier->payloads)], [1, 1]);

// DELETE /applications/{id}/hospitality-orders/{orderId}
$repo = $newRepo();
$notifier = new RecordingNotifier();
$delete = $invoke($makeCitizen($repo, $notifier, $application), 'deleteOrder', 'DELETE', ['id' => 84091, 'orderId' => 7]);
$same('DELETE hospitality-orders/{id}: no exception', $delete['threw']?->getMessage(), null);
$same('DELETE hospitality-orders/{id}: 200 and exactly the message', [$delete['status'], $delete['body']], [200, '{"message":"Order deleted"}']);
$assertCitizenBody('DELETE hospitality-orders/{id}', $delete['body']);
$same('DELETE hospitality-orders/{id}: cancelled once, one case entry, one mail', [array_count_values($repo->calls)['updateStatus:cancelled'] ?? 0, count($repo->entries), count($notifier->payloads)], [1, 1, 1]);

// The static belt: every order the citizen controller serialises goes through the choke point.
$citizenSource = (string)file_get_contents($root . '/src/modules/bookingfrontend/controllers/applications/HospitalityOrderController.php');
$serialisations = preg_match_all('/new HospitalityOrder\(/', $citizenSource);
$check('control: the citizen controller serialises orders (the pattern finds them)', $serialisations >= 2, "found {$serialisations}");
$same('every `new HospitalityOrder(` in the citizen controller is wrapped in CitizenHospitalityOrder::present(',
    preg_match_all('/CitizenHospitalityOrder::present\(\s*\(new HospitalityOrder\(/', $citizenSource), $serialisations);

// =======================================================================================================
// 5. (6) A throw AFTER the committed write must not become a 500
// =======================================================================================================
$throwables = [
    'a RuntimeException' => static fn() => new \RuntimeException('re-read failed'),
    'a PDOException' => static fn() => new \PDOException('SQLSTATE[08006] connection lost'),
    'a TypeError' => static fn() => new \TypeError('re-read blew up with an Error'),
];

foreach ($throwables as $label => $make) {
    // --- cancel (D1) ---
    $repo = $newRepo();
    $notifier = new RecordingNotifier();
    $repo->readThrowsAfterWrite = $make();
    $clearLog();
    $r = $invoke($makeCitizen($repo, $notifier, $application), 'deleteOrder', 'DELETE', ['id' => 84091, 'orderId' => 7]);
    $same("cancel, the re-read throws {$label}: nothing escapes", $r['threw']?->getMessage(), null);
    $same("cancel, the re-read throws {$label}: the answer is the success it is", [$r['status'], $r['body']], [200, '{"message":"Order deleted"}']);
    $same("cancel, the re-read throws {$label}: the cancel was written once", array_count_values($repo->calls)['updateStatus:cancelled'] ?? 0, 1);
    $same("cancel, the re-read throws {$label}: no case entry and no mail", [count($repo->entries), count($notifier->payloads)], [0, 0]);
    $contains("cancel, the re-read throws {$label}: logged, naming the order", $logTail(), 'for order 7');

    // --- add (C1): the re-read IS the response body, so the fallback is {"id":N} ---
    $repo = $newRepo();
    $notifier = new RecordingNotifier();
    $repo->readThrowsAfterWrite = $make();
    $clearLog();
    $r = $invoke(
        $makeCitizen($repo, $notifier, $application), 'createOrder', 'POST', ['id' => 84091],
        json_encode(['hospitality_id' => 3, 'location_resource_id' => 5, 'serving_time_iso' => gmdate('c', strtotime('+30 days')), 'lines' => [['hospitality_article_id' => 11, 'quantity' => 2]]])
    );
    $same("add, the re-read throws {$label}: nothing escapes", $r['threw']?->getMessage(), null);
    $same("add, the re-read throws {$label}: 201 with exactly {\"id\":N}", [$r['status'], json_decode($r['body'], true)], [201, ['id' => 100]]);
    $same("add, the re-read throws {$label}: the order was created once", array_count_values($repo->calls)['create'] ?? 0, 1);
    $same("add, the re-read throws {$label}: no case entry and no mail", [count($repo->entries), count($notifier->payloads)], [0, 0]);
    $contains("add, the re-read throws {$label}: logged, naming the order", $logTail(), 'for order 100');

    // --- change (U1) ---
    $repo = $newRepo();
    $notifier = new RecordingNotifier();
    $repo->readThrowsAfterWrite = $make();
    $clearLog();
    $r = $invoke($makeCitizen($repo, $notifier, $application), 'updateOrder', 'PUT', ['id' => 84091, 'orderId' => 7], json_encode(['comment' => 'Ny kommentar']));
    $same("change, the re-read throws {$label}: nothing escapes", $r['threw']?->getMessage(), null);
    $same("change, the re-read throws {$label}: 200 with exactly {\"id\":N}", [$r['status'], json_decode($r['body'], true)], [200, ['id' => 7]]);
    $same("change, the re-read throws {$label}: the change was written once", array_count_values($repo->calls)['update'] ?? 0, 1);
    $same("change, the re-read throws {$label}: no case entry and no mail", [count($repo->entries), count($notifier->payloads)], [0, 0]);
    $contains("change, the re-read throws {$label}: logged, naming the order", $logTail(), 'for order 7');
}

// Controls: the guard must not hide a failure that IS a failure.
$repo = $newRepo();
$notifier = new RecordingNotifier();
$repo->writeThrows = new \RuntimeException('UPDATE failed');
$r = $invoke($makeCitizen($repo, $notifier, $application), 'deleteOrder', 'DELETE', ['id' => 84091, 'orderId' => 7]);
$same('control: the cancel WRITE fails -> still a 500 that says so', [$r['status'], $r['body']], [500, '{"error":"Failed to delete order"}']);
$same('control: ...and nobody is told of a cancel that did not happen', [count($repo->entries), count($notifier->payloads)], [0, 0]);

$repo = $newRepo();
$notifier = new RecordingNotifier();
$repo->beforeThrows = new \RuntimeException('read before the write failed');
$r = $invoke($makeCitizen($repo, $notifier, $application), 'deleteOrder', 'DELETE', ['id' => 84091, 'orderId' => 7]);
$same('control: the read BEFORE the cancel fails -> 500 (nothing was committed, so it is true)', [$r['status'], $r['body']], [500, '{"error":"Failed to delete order"}']);
$same('control: ...and the order was not cancelled', array_count_values($repo->calls)['updateStatus:cancelled'] ?? 0, 0);

$repo = $newRepo();
$repo->readThrowsAfterWrite = new \RuntimeException('re-read failed');
$check('control: the same stub DOES make the real re-read throw (so the arms above test something)', (function () use ($repo) {
    $repo->wrote = true;
    try {
        $repo->getOrderWithLines(7);
        return false;
    } catch (\RuntimeException $e) {
        return true;
    }
})());

unlink($logFile);

// =======================================================================================================
$total = $passed + count($failures);
if ($failures) {
    foreach ($failures as $f) {
        echo 'FAIL ', $f, "\n";
    }
    echo 'Citizen harness: FAIL ', count($failures), " of {$total} checks\n";
    exit(1);
}
echo "Citizen harness: PASS {$total} checks on PHP ", PHP_VERSION, "\n";
