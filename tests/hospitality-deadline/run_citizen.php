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
 *   (7) the quantity of a catering line is a positive WHOLE number for every unit (task 47994): the value table of
 *       HospitalityOrderQuantity, and the real createOrder / updateOrder refusing a fraction, zero, a negative, a non-number
 *       or a number above the column's capacity with a translated 400 and NOTHING written, and still saving a whole one.
 *   (8) the same rule in the ADMIN order API (addLine, updateLine): a translated 400 in that controller's {error} shape, nothing
 *       written, the judged int stored and recorded in the changelog (task 47994, C1b).
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
    'modules/booking/services/CateringChangeText.php',
    'modules/booking/services/TranslationLangSwitch.php',
    'modules/booking/repositories/HospitalityOrderRepository.php',
    'modules/bookingfrontend/services/applications/CateringChangeNotifier.php',
    'modules/bookingfrontend/services/applications/CitizenHospitalityOrder.php',
    'modules/bookingfrontend/controllers/applications/HospitalityOrderController.php',
    'modules/booking/controllers/HospitalityOrderController.php',
] as $file) {
    require_once $root . '/src/' . $file;
}
// The whole-number rule (task 47994) is a class of its own. Guarded, so that a tree WITHOUT it (the base the rows of section 7 are
// shown red on) answers with failed rows, not with a fatal error.
$quantityFile = $root . '/src/modules/booking/services/HospitalityOrderQuantity.php';
if (is_file($quantityFile)) {
    require_once $quantityFile;
}
// The amount bound (§49596) is judged by both controllers' write paths; its own rows are in tests/hospitality-amount/.
$amountFile = $root . '/src/modules/booking/services/HospitalityOrderAmount.php';
if (is_file($amountFile)) {
    require_once $amountFile;
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
    /** @var list<array> what updateOrderLine() was handed, as handed */
    public array $lineUpdates = [];
    /** @var list<mixed> the `quantity` addOrderLine() was handed, as handed (an int stays an int): what was STORED, before the column's own rounding */
    public array $lineQuantities = [];
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

    /** One transaction (§49596). The stub has no connection: it runs the work and counts the bracket. */
    public int $transactions = 0;

    public function transactional(callable $work): mixed
    {
        $this->transactions++;
        return $work();
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
        $this->calls[] = 'addOrderLine';
        $this->lineQuantities[] = $data['quantity'] ?? 1;
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

    public function getOrderLineById(int $lineId): ?array
    {
        foreach ($this->lines as $rows) {
            foreach ($rows as $line) {
                if ((int)$line['id'] === $lineId) {
                    return $line;
                }
            }
        }
        return null;
    }

    public function updateOrderLine(int $lineId, array $data): bool
    {
        $this->calls[] = 'updateOrderLine';
        $this->lineUpdates[] = $data;
        $this->wrote = true;
        return true;
    }

    public function deleteOrderLine(int $lineId): bool
    {
        $this->calls[] = 'deleteOrderLine';
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
    /** The article's price and tax code. 45.00 at code 1 (25 %) unless a row needs an amount that fits (§49596). */
    public static float $price = 45.0;
    public static int $taxCode = 1;

    public function __construct()
    {
    }

    public function resolveEffectivePricing(int $articleId): ?array
    {
        return ['effective_price' => self::$price, 'effective_tax_code' => self::$taxCode];
    }

    /** fm_ecomva as the fixture lines describe it: code 1 is 25 % (their tax_percent), code 0 is 0 %. */
    public function getTaxPercentByCode(): array
    {
        return [0 => 0, 1 => 25];
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
            'unit_price' => '45.00', 'tax_code' => 1, 'tax_percent' => 25, 'amount' => '135.00', 'comment' => null,
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
        'tax_code' => 1, 'tax_percent' => 25, 'amount' => '135.00', 'comment' => null, 'article_name' => 'Kaffe', 'unit' => 'stk',
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
// 7. The quantity of a catering line is a positive WHOLE number, for every unit (task 47994, C1; operator ruling,
//    request 206): refused with a translated 400 BEFORE anything is written
// =======================================================================================================
$quantityClass = '\\App\\modules\\booking\\services\\HospitalityOrderQuantity';
$haveQuantity = class_exists($quantityClass, false);
$check('quantity: HospitalityOrderQuantity is loaded from this tree', $haveQuantity, "the class does not exist under {$root}");

// [the JSON literal as a request body carries it, what whole() answers]. A string is judged by its SPELLING, a number by its VALUE.
$wholeTable = [
    ['2', 2], ['"2"', 2], ['2.0', 2], ['"2.0"', 2], ['1e1', 10], ['"007"', 7], ['"00000000000000000002"', 2], ['1', 1], ['"1.00"', 1],
    ['99999999', 99999999], ['99999999.0', 99999999], ['"99999999"', 99999999],
    ['0', null], ['"0"', null], ['0.0', null], ['-0.0', null], ['-1', null], ['"-1"', null], ['-0.5', null],
    ['2.5', null], ['"2.5"', null], ['2.004', null], ['0.5', null], ['"1e1"', null], ['" 2"', null], ['"2 "', null], ['"2\\n"', null],
    ['"2abc"', null], ['"abc"', null], ['""', null], ['"0x2"', null], ['"+2"', null], ['"2."', null], ['".5"', null], ['"2,0"', null],
    ['true', null], ['false', null], ['null', null], ['[2]', null], ['[]', null], ['{"a":1}', null],
    ['1e400', null], ['100000000', null], ['"100000000"', null], ['12345678901234567890', null], ['"12345678901234567890"', null], ['9007199254740993', null],
    // 2^64 + 8192 as a float: PHP's (int) wraps it to 8192, which would pass the range test, so the float branch must refuse it by value first.
    ['18446744073709560000', null], ['18446744073709563904', null],
    // The NEGATIVE half of the same wrap (task 48533): a JSON number below -2^63 is a float, and (int) of it wraps modulo 2^64 to a
    // POSITIVE number in 1..MAX that the final range test would accept. -(2^64 - 2048) wraps to +2048, -(2^64 - 4096) to +4096,
    // -(2^65 - 4096) to +4096 (the next period). -1e30 and -INF (1e400's negative) wrap to something refused anyway; they are rows
    // so that a lower bound written some other way cannot lose them.
    ['-18446744073709549568', null], ['-18446744073709547520', null], ['-36893488147419099136', null], ['-1e30', null], ['-1e400', null],
    // Positive control: the numbers those floats wrap TO are valid quantities, so a wrap that got through really would be accepted.
    ['2048', 2048], ['4096', 4096], ['2048.0', 2048],
];
if ($haveQuantity) {
    foreach ($wholeTable as [$literal, $want]) {
        $same("quantity whole(): {$literal} -> " . var_export($want, true), $quantityClass::whole(json_decode("[{$literal}]", true)[0]), $want);
    }
    $same('quantity ofLine(): no `quantity` key is 1', $quantityClass::ofLine(['hospitality_article_id' => 11]), 1);
    $same('quantity ofLine(): `quantity: null` is 1', $quantityClass::ofLine(['quantity' => null]), 1);
    $same('quantity ofLine(): 3 is 3', $quantityClass::ofLine(['quantity' => 3]), 3);
    $same('quantity ofLine(): 2.5 is refused', $quantityClass::ofLine(['quantity' => 2.5]), null);
    $same('quantity ofLine(): a line that is not an array (5) is left to the article-id refusal: 1', $quantityClass::ofLine(5), 1);
    $same('quantity ofLine(): a string line is left to the article-id refusal: 1', $quantityClass::ofLine('abc'), 1);
}

$servingIsoQ = gmdate('c', strtotime('+30 days'));
/** POST an order with these lines (a JSON array text). Returns [response, repo, notifier]. */
$createQ = static function (string $linesJson, array $app = [], int $location = 5) use ($newRepo, $makeCitizen, $invoke, $application, $servingIsoQ): array {
    $repo = $newRepo();
    $notifier = new RecordingNotifier();
    $body = '{"hospitality_id":3,"location_resource_id":' . $location . ',"serving_time_iso":"' . $servingIsoQ . '","lines":' . $linesJson . '}';
    return [$invoke($makeCitizen($repo, $notifier, array_replace($application, $app)), 'createOrder', 'POST', ['id' => 84091], $body), $repo, $notifier];
};
/** PUT order 7 (comment 'Uten nøtter', one line of 3.00) with this JSON body text. */
$updateQ = static function (string $json, array $app = []) use ($newRepo, $makeCitizen, $invoke, $application): array {
    $repo = $newRepo();
    $notifier = new RecordingNotifier();
    return [$invoke($makeCitizen($repo, $notifier, array_replace($application, $app)), 'updateOrder', 'PUT', ['id' => 84091, 'orderId' => 7], $json), $repo, $notifier];
};
$writeCalls = ['create', 'update', 'addOrderLine', 'deleteOrderLine'];
$wroteNothing = static fn(CitizenStubRepo $repo, RecordingNotifier $notifier): array =>
    [$repo->wrote, array_values(array_intersect($repo->calls, $writeCalls)), count($repo->entries), count($notifier->payloads)];

// Refused: the request answers 400 with the translated error, the code and the offending line, and NOTHING is written.
$refusedQuantities = ['2.5', '"2.5"', '0.5', '2.004', '0', '-1', '"abc"', 'true', 'false', '[2]', '1e400', '100000000', '"2abc"', '"1e1"', '" 2"', '0.0',
    // Beyond int64 and negative (task 48533): (int) wraps these to +2048 / +4096 unless the float is refused by value first. Shared by
    // all four APIs: the applicant's create and update here, the admin addLine and updateLine in section 8.
    '-18446744073709549568', '-18446744073709547520', '-36893488147419099136', '-1e30', '-1e400', '-0.0'];
foreach ($refusedQuantities as $literal) {
    [$r, $repo, $notifier] = $createQ('[{"hospitality_article_id":11,"quantity":' . $literal . '}]');
    $json = json_decode($r['body'], true) ?: [];
    $same("create, quantity {$literal}: no exception", $r['threw']?->getMessage(), null);
    $same("create, quantity {$literal}: 400, the translated error, code and line", [$r['status'], $json['error'] ?? null, $json['code'] ?? null, $json['line'] ?? null],
        [400, '!catering_quantity_not_whole', 'quantity_not_whole', 0]);
    $same("create, quantity {$literal}: nothing written (no order, no line, no case entry, no mail)", $wroteNothing($repo, $notifier), [false, [], 0, 0]);

    [$r, $repo, $notifier] = $updateQ('{"comment":"CHANGED","lines":[{"hospitality_article_id":11,"quantity":' . $literal . '}]}');
    $json = json_decode($r['body'], true) ?: [];
    $same("update, quantity {$literal}: no exception", $r['threw']?->getMessage(), null);
    $same("update, quantity {$literal}: 400, the translated error, code and line", [$r['status'], $json['error'] ?? null, $json['code'] ?? null, $json['line'] ?? null],
        [400, '!catering_quantity_not_whole', 'quantity_not_whole', 0]);
    $same("update, quantity {$literal}: nothing written: not the comment, not the lines (the refusal comes BEFORE the order's own fields are saved)",
        [$wroteNothing($repo, $notifier), $repo->orders[7]['comment'], array_column($repo->lines[7], 'quantity')], [[false, [], 0, 0], 'Uten nøtter', ['3.00']]);
}

// The second line is the bad one: the answer names line 1, and the good line before it was not written either.
[$r, $repo, $notifier] = $createQ('[{"hospitality_article_id":11,"quantity":2},{"hospitality_article_id":11,"quantity":2.5}]');
$same('create, line 1 is fractional: 400 naming line 1, nothing written', [$r['status'], json_decode($r['body'], true)['line'] ?? null, $wroteNothing($repo, $notifier)], [400, 1, [false, [], 0, 0]]);
[$r, $repo, $notifier] = $updateQ('{"comment":"CHANGED","lines":[{"hospitality_article_id":11,"quantity":2},{"hospitality_article_id":11,"quantity":2.5}]}');
$same('update, line 1 is fractional: 400 naming line 1, nothing written, comment unchanged',
    [$r['status'], json_decode($r['body'], true)['line'] ?? null, $wroteNothing($repo, $notifier), $repo->orders[7]['comment']], [400, 1, [false, [], 0, 0], 'Uten nøtter']);

// Accepted: whole numbers, in any spelling the table accepts, are stored as the INT they were read as.
foreach ([['2', 2], ['"2"', 2], ['2.0', 2], ['"2.0"', 2], ['1e1', 10], ['"007"', 7], ['99999999', 99999999]] as [$literal, $want]) {
    // 99,999,999 × 45.00 is 4,499,999,955.00, which no money column holds: since §49596 it is refused with a 422 before anything
    // is written (tests/hospitality-amount). The quantity's upper edge is held here at a price that fits: 1.00 at 0 %.
    [StubArticleRepo::$price, StubArticleRepo::$taxCode] = $literal === '99999999' ? [1.0, 0] : [45.0, 1];
    [$r, $repo, $notifier] = $createQ('[{"hospitality_article_id":11,"quantity":' . $literal . '}]');
    // Two rows each: that it is SAVED (true before this change too) and what was stored (an int; before this change a float).
    $same("create, quantity {$literal}: 201 and one order", [$r['status'], array_count_values($repo->calls)['create'] ?? 0], [201, 1]);
    $same("create, quantity {$literal}: the line is stored as the int {$want}", $repo->lineQuantities, [$want]);
    [$r, $repo, $notifier] = $updateQ('{"comment":"CHANGED","lines":[{"hospitality_article_id":11,"quantity":' . $literal . '}]}');
    $same("update, quantity {$literal}: 200, the comment saved, the order written once",
        [$r['status'], $repo->orders[7]['comment'], array_count_values($repo->calls)['update'] ?? 0], [200, 'CHANGED', 1]);
    $same("update, quantity {$literal}: the replacing line is stored as the int {$want}", $repo->lineQuantities, [$want]);
}
[StubArticleRepo::$price, StubArticleRepo::$taxCode] = [45.0, 1];
[$r, $repo] = $createQ('[{"hospitality_article_id":11}]');
$same('create, no quantity key: 201 and one line (unchanged)', [$r['status'], count($repo->lineQuantities)], [201, 1]);
$same('create, no quantity key: the line is the int 1', $repo->lineQuantities, [1]);
[$r, $repo] = $createQ('[{"hospitality_article_id":11,"quantity":null}]');
$same('create, quantity null: 201 and one line (unchanged)', [$r['status'], count($repo->lineQuantities)], [201, 1]);
$same('create, quantity null: the line is the int 1', $repo->lineQuantities, [1]);
[$r, $repo] = $updateQ('{"comment":"CHANGED"}');
$same('update with NO lines (a comment-only edit): 200, the comment saved, the lines untouched',
    [$r['status'], $repo->orders[7]['comment'], $repo->lineQuantities, array_column($repo->lines[7], 'quantity')], [200, 'CHANGED', [], ['3.00']]);

// Order: the refusals that were already there keep their place relative to the new one.
[$r, $repo, $notifier] = $createQ('[{"hospitality_article_id":11,"quantity":2.5}]', ['status' => 'REJECTED']);
$same('order: the deadline guard (409) answers before the quantity (create)', [$r['status'], $wroteNothing($repo, $notifier)], [409, [false, [], 0, 0]]);
[$r, $repo, $notifier] = $updateQ('{"comment":"CHANGED","lines":[{"hospitality_article_id":11,"quantity":2.5}]}', ['status' => 'REJECTED']);
$same('order: the deadline guard (409) answers before the quantity (update)', [$r['status'], $wroteNothing($repo, $notifier)], [409, [false, [], 0, 0]]);
[$r, $repo, $notifier] = $createQ('[{"quantity":2.5}]');
$same('order: a line without an article id is refused for THAT first (create)', [$r['status'], json_decode($r['body'], true)['error'] ?? null], [400, 'Line 0: hospitality_article_id is required']);
[$r, $repo, $notifier] = $createQ('[{"hospitality_article_id":11,"quantity":2.5}]', [], 99);
$same('order: an invalid delivery location is refused before the quantity (create)', [$r['status'], json_decode($r['body'], true)['error'] ?? null], [400, 'Invalid delivery location for this hospitality']);
[$r, $repo, $notifier] = $updateQ('{"location_resource_id":99,"lines":[{"hospitality_article_id":11,"quantity":2.5}]}');
$same('order: an invalid delivery location is refused before the quantity (update)', [$r['status'], json_decode($r['body'], true)['error'] ?? null, $wroteNothing($repo, $notifier)],
    [400, 'Invalid delivery location', [false, [], 0, 0]]);

// The translated text exists in all three languages, once each.
foreach (['no', 'nn', 'en'] as $langCode) {
    $langRows = preg_grep('/^catering_quantity_not_whole\tbookingfrontend\t' . $langCode . '\t\S/', explode("\n", (string)file_get_contents($root . "/src/modules/bookingfrontend/setup/phpgw_{$langCode}.lang")));
    $same("lang: catering_quantity_not_whole has exactly one non-empty {$langCode} row in bookingfrontend", count($langRows), 1);
}
$citizenText = (string)file_get_contents($root . '/src/modules/bookingfrontend/controllers/applications/HospitalityOrderController.php');
$same('the citizen controller no longer casts a line quantity to float, and no English «quantity must be positive» is left',
    [substr_count($citizenText, "(float)\$line['quantity']"), substr_count($citizenText, 'quantity must be positive')], [0, 0]);


// =======================================================================================================
// 8. The same rule in the ADMIN order API: addLine and updateLine (task 47994, C1b). An officer's request used to reach the
//    database as it was sent: 2.5 was stored, and 0, -1 or a word came back as a 500 carrying the database's own message.
// =======================================================================================================
$adminOrder = static function (CitizenStubRepo $repo) use ($adminRef): object {
    $controller = $adminRef->newInstanceWithoutConstructor();
    $adminRef->getProperty('repository')->setValue($controller, $repo);
    $adminRef->getProperty('articleRepository')->setValue($controller, new StubArticleRepo());
    $adminRef->getProperty('userSettings')->setValue($controller, ['account_id' => 5]);
    $adminRef->getProperty('acl')->setValue($controller, new class extends \App\modules\phpgwapi\security\Acl {
        public function __construct()
        {
        }

        public function check($location, $required, $appname = '')
        {
            return true;
        }
    });
    return $controller;
};
/** POST /booking/hospitality-orders/{id}/lines with this JSON body text. Returns [response, repo]. */
$addLineQ = static function (string $json, int $orderId = 7, ?callable $arrange = null) use ($newRepo, $adminOrder, $invoke): array {
    $repo = $newRepo();
    if ($arrange !== null) {
        $arrange($repo);
    }
    return [$invoke($adminOrder($repo), 'addLine', 'POST', ['id' => $orderId], $json), $repo];
};
/** PUT /booking/hospitality-orders/{id}/lines/{lineId} (line 407 is order 7's, line 408 is order 8's, both 3.00). Returns [response, repo]. */
$updateLineQ = static function (string $json, int $orderId = 7, int $lineId = 407, ?callable $arrange = null) use ($newRepo, $adminOrder, $invoke): array {
    $repo = $newRepo();
    if ($arrange !== null) {
        $arrange($repo);
    }
    return [$invoke($adminOrder($repo), 'updateLine', 'PUT', ['id' => $orderId, 'lineId' => $lineId], $json), $repo];
};
$adminWriteCalls = ['addOrderLine', 'updateOrderLine', 'deleteOrderLine', 'create', 'update'];
$adminWroteNothing = static fn(CitizenStubRepo $repo): array => [$repo->wrote, array_values(array_intersect($repo->calls, $adminWriteCalls)), count($repo->entries)];
$adminRefusal = '!booking.hospitality_order_quantity_not_whole';

foreach ($refusedQuantities as $literal) {
    [$r, $repo] = $addLineQ('{"changelog_comment":"Rettet","hospitality_article_id":11,"quantity":' . $literal . '}');
    $same("admin addLine, quantity {$literal}: no exception", $r['threw']?->getMessage(), null);
    $same("admin addLine, quantity {$literal}: 400 with the translated error (this controller's {error} shape)",
        [$r['status'], json_decode($r['body'], true)], [400, ['error' => $adminRefusal]]);
    $same("admin addLine, quantity {$literal}: nothing written (no line, no changelog entry)", $adminWroteNothing($repo), [false, [], 0]);
}
// updateLine also refuses a PRESENT null: the column is NOT NULL and there is no default to fall back on.
foreach (array_merge($refusedQuantities, ['null']) as $literal) {
    [$r, $repo] = $updateLineQ('{"changelog_comment":"Rettet","quantity":' . $literal . '}');
    $same("admin updateLine, quantity {$literal}: no exception", $r['threw']?->getMessage(), null);
    $same("admin updateLine, quantity {$literal}: 400 with the translated error",
        [$r['status'], json_decode($r['body'], true)], [400, ['error' => $adminRefusal]]);
    $same("admin updateLine, quantity {$literal}: nothing written (the line is still 3.00, no changelog entry)",
        [$adminWroteNothing($repo), array_column($repo->lines[7], 'quantity')], [[false, [], 0], ['3.00']]);
}

// Accepted: stored (and recorded in the changelog) as the int that was judged.
foreach ([['2', 2], ['"2"', 2], ['2.0', 2], ['"2.0"', 2], ['1e1', 10], ['99999999', 99999999]] as [$literal, $want]) {
    // As in section 7: 99,999,999 is held at a price that fits (1.00 at 0 %), for the added line and for the stored one (§49596).
    $fits = $literal === '99999999';
    [StubArticleRepo::$price, StubArticleRepo::$taxCode] = $fits ? [1.0, 0] : [45.0, 1];
    // An added line of 99,999,999.00 fits only an order holding nothing else; an updated one only if it is the order's one line.
    $arrangeAdd = $fits ? static function (CitizenStubRepo $repo): void {
        $repo->lines[7] = [];
    } : null;
    $arrangeUpdate = $fits ? static function (CitizenStubRepo $repo): void {
        $repo->lines[7][0] = array_replace($repo->lines[7][0], ['unit_price' => '1.00', 'tax_code' => 0, 'tax_percent' => 0, 'amount' => '3.00']);
    } : null;
    [$r, $repo] = $addLineQ('{"changelog_comment":"Rettet","hospitality_article_id":11,"quantity":' . $literal . '}', 7, $arrangeAdd);
    $same("admin addLine, quantity {$literal}: 201, one line added, one changelog entry", [$r['status'], array_count_values($repo->calls)['addOrderLine'] ?? 0, count($repo->entries)], [201, 1, 1]);
    $same("admin addLine, quantity {$literal}: the line AND the changelog carry the int {$want}", [$repo->lineQuantities, $repo->entries[0]['new_value']['quantity'] ?? null], [[$want], $want]);
    [$r, $repo] = $updateLineQ('{"changelog_comment":"Rettet","quantity":' . $literal . '}', 7, 407, $arrangeUpdate);
    $same("admin updateLine, quantity {$literal}: 200, the line updated once", [$r['status'], array_count_values($repo->calls)['updateOrderLine'] ?? 0], [200, 1]);
    $same("admin updateLine, quantity {$literal}: the repository is handed the int {$want}", array_column($repo->lineUpdates, 'quantity'), [$want]);
}
[StubArticleRepo::$price, StubArticleRepo::$taxCode] = [45.0, 1];
[$r, $repo] = $addLineQ('{"changelog_comment":"Rettet","hospitality_article_id":11}');
$same('admin addLine, no quantity key: 201 and one line (unchanged)', [$r['status'], count($repo->lineQuantities)], [201, 1]);
$same('admin addLine, no quantity key: the line is the int 1', $repo->lineQuantities, [1]);
[$r, $repo] = $addLineQ('{"changelog_comment":"Rettet","hospitality_article_id":11,"quantity":null}');
$same('admin addLine, quantity null: 201 and one line (unchanged)', [$r['status'], count($repo->lineQuantities)], [201, 1]);
$same('admin addLine, quantity null: the line is the int 1', $repo->lineQuantities, [1]);
[$r, $repo] = $updateLineQ('{"changelog_comment":"Rettet","comment":"Uten gluten"}');
$same('admin updateLine with NO quantity key (a comment-only edit): 200, and the repository is not handed a quantity',
    [$r['status'], array_count_values($repo->calls)['updateOrderLine'] ?? 0, array_key_exists('quantity', $repo->lineUpdates[0] ?? ['quantity' => 'present'])], [200, 1, false]);

// Order: the refusals that were already there keep their place relative to the new one.
[$r, $repo] = $addLineQ('{"hospitality_article_id":11,"quantity":2.5}');
$same('admin order: changelog_comment is asked for before the quantity (addLine)', [$r['status'], json_decode($r['body'], true)], [400, ['error' => 'changelog_comment is required']]);
[$r, $repo] = $addLineQ('{"changelog_comment":"Rettet","quantity":2.5}');
$same('admin order: the article id is asked for before the quantity (addLine)', [$r['status'], json_decode($r['body'], true)], [400, ['error' => 'hospitality_article_id is required']]);
[$r, $repo] = $addLineQ('{"changelog_comment":"Rettet","hospitality_article_id":11,"quantity":2.5}', 999);
$same('admin order: an unknown order is 404 before the quantity (addLine)', [$r['status'], json_decode($r['body'], true)], [404, ['error' => 'Order not found']]);
[$r, $repo] = $updateLineQ('{"quantity":2.5}');
$same('admin order: changelog_comment is asked for before the quantity (updateLine)', [$r['status'], json_decode($r['body'], true)], [400, ['error' => 'changelog_comment is required']]);
[$r, $repo] = $updateLineQ('{"changelog_comment":"Rettet","quantity":2.5}', 7, 408);
$same('admin order: a line of another order is 404 before the quantity (updateLine)', [$r['status'], json_decode($r['body'], true)], [404, ['error' => 'Order line not found']]);

foreach (['no', 'nn', 'en'] as $langCode) {
    $langRows = preg_grep('/^hospitality_order_quantity_not_whole\tbooking\t' . $langCode . '\t\S/', explode("\n", (string)file_get_contents($root . "/src/modules/booking/setup/phpgw_{$langCode}.lang")));
    $same("lang: hospitality_order_quantity_not_whole has exactly one non-empty {$langCode} row in booking", count($langRows), 1);
}
$adminText = (string)file_get_contents($root . '/src/modules/booking/controllers/HospitalityOrderController.php');
$same('the admin controller no longer hands the raw `quantity` on: no «$body[\'quantity\'] ?? 1»', substr_count($adminText, "\$body['quantity'] ?? 1"), 0);


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
