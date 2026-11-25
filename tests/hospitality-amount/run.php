<?php

/**
 * Hermetic tests for the catering amount bound (§49596): a line or an order the money columns cannot hold is refused
 * with a 422 BEFORE anything is written.
 *
 *   php tests/hospitality-amount/run.php
 *
 * No database, no mail, no HTTP server. The real controllers run with the collaborators that touch the database
 * stubbed. The order repository stub behaves like the columns it replaces: every money value it is handed is turned
 * into the string PDO would bind and rounded as numeric(10,2) rounds it, and one that does not fit throws the
 * PDOException PostgreSQL's «numeric field overflow» becomes (22003). Without that, a tree with no bound would
 * «store» 4e11 and look green. The VAT table is fm_ecomva as it is in the test database, plus the 15 % code of the
 * lane-5 fixture.
 *
 *   A  HospitalityOrderAmount on its own: the value table, against a bcmath oracle (exact decimals, billing's formula)
 *   B  the applicant's createOrder / updateOrder (lanes 1-8, 11, 12, 13)
 *   C  the admin's addLine / updateLine (lane 9)
 *   D  the translations (lane 15)
 *
 * What a transaction rolls back, and what the database itself refuses, is run_pg.php's: this stub has no rollback.
 *
 * Environment (optional): HD_ROOT = tree holding src/ (default: this repo; point it at a base tree to see the lanes
 * red); VENDOR_AUTOLOAD = composer autoload (default: this repo's). Needs bcmath (bcround, PHP >= 8.4) for the oracle.
 *
 * Exit code: 0 all pass, 1 any failure.
 */

date_default_timezone_set('Europe/Oslo');
error_reporting(E_ALL & ~E_DEPRECATED);

$root = getenv('HD_ROOT') ?: dirname(__DIR__, 2);
require_once getenv('VENDOR_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

// The stubs' parents reach the legacy includes through these two constants; they point at THIS repo, never at HD_ROOT.
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
    'modules/booking/services/HospitalityOrderQuantity.php',
    'modules/booking/services/CateringText.php',
    'modules/booking/services/CateringLangSwitch.php',
    'modules/booking/services/CateringMailRenderer.php',
    'modules/booking/services/CateringChangeText.php',
    'modules/booking/services/TranslationLangSwitch.php',
    'modules/booking/repositories/HospitalityOrderRepository.php',
    'modules/booking/repositories/HospitalityArticleRepository.php',
    'modules/bookingfrontend/services/applications/CateringChangeNotifier.php',
    'modules/bookingfrontend/services/applications/CitizenHospitalityOrder.php',
    'modules/bookingfrontend/controllers/applications/HospitalityOrderController.php',
    'modules/booking/controllers/HospitalityOrderController.php',
] as $file) {
    require_once $root . '/src/' . $file;
}
// Guarded, so that a tree WITHOUT the bound (the base the lanes are shown red on) answers with failed rows, not a fatal.
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
use Psr\Http\Message\ServerRequestInterface;

// The global lang() the controllers reach for when they refuse: the key, marked, so a row can see WHICH text was asked for.
if (!function_exists('lang')) {
    function lang($key, ...$args)
    {
        return "!{$key}";
    }
}

// --- assertion kit ---------------------------------------------------------------------------------------------------
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
    $check($name, $got === $want, 'got ' . json_encode($got, JSON_UNESCAPED_UNICODE) . ' want ' . json_encode($want, JSON_UNESCAPED_UNICODE));
};

$logFile = tempnam(sys_get_temp_dir(), 'hd-amount-log-');
ini_set('error_log', $logFile);
ini_set('log_errors', '1');

$check('oracle: bcmath with bcround() is loaded (the expected values below are computed with it)', function_exists('bcround'));
if (!function_exists('bcround')) {
    echo "Amount harness: FAIL (no bcround: install bcmath, PHP >= 8.4)\n";
    exit(1);
}

// =====================================================================================================================
// The columns and the VAT table, as the test database has them
// =====================================================================================================================
/** fm_ecomva (SELECT id, percent_ FROM fm_ecomva) plus the lane-5 fixture's code 15. */
const TAX = [0 => 0, 9 => 25, 12 => 25, 15 => 15, 60 => 25, 75 => 25];
const MAX_COLUMN = '99999999.99';

/**
 * What numeric(10,2) makes of a value PDO binds: the string PHP turns it into, rounded half away from zero to two
 * decimals. Null when it does not fit (PostgreSQL: «numeric field overflow»). Exact (bcmath), independent of the class.
 */
function hd_column(mixed $value): ?string
{
    $s = is_string($value) ? trim($value) : (string)$value;
    if (stripos($s, 'e') !== false) {
        // PHP writes a float beyond 1e15 with an exponent; bcmath does not read one. Such a value is far beyond the column.
        return abs((float)$s) < 1e8 ? bcround(sprintf('%.6F', (float)$s), 2) : null;
    }
    $rounded = bcround($s, 2);
    return bccomp(ltrim($rounded, '-'), MAX_COLUMN, 2) > 0 ? null : $rounded;
}

/** Billing's own formula for one line (async_task_update_reservation_state), in exact decimals: [net, tax] in kr, rounded. */
function hd_billing_line(string $quantity, string $unitPrice, ?int $taxCode): array
{
    $amount = bcmul($quantity, $unitPrice, 8);
    $percent = TAX[(int)$taxCode] ?? 0;
    $tax = bcdiv(bcmul($amount, (string)$percent, 8), '100', 8);
    return [bcround($amount, 2), bcround($tax, 2)];
}

/** Σ(net + tax) over lines [[q, price, code], ...] in øre (int), billing's formula. */
function hd_gross_ore(array $lines): int
{
    $sum = '0';
    foreach ($lines as [$q, $p, $c]) {
        [$net, $tax] = hd_billing_line((string)$q, (string)$p, $c);
        $sum = bcadd($sum, bcadd($net, $tax, 2), 2);
    }
    return (int)bcmul($sum, '100', 0);
}

// =====================================================================================================================
// The stubs
// =====================================================================================================================
final class ColumnRepo extends HospitalityOrderRepository
{
    /** @var array<int, array> */
    public array $orders = [];
    /** @var array<int, list<array>> */
    public array $lines = [];
    /** @var list<string> every write, in order */
    public array $writes = [];
    /** @var list<array> */
    public array $entries = [];
    public int $transactions = 0;
    private int $nextOrderId = 300;
    private int $nextLineId = 900;

    public function __construct()
    {
    }

    /** The stub has no connection and no rollback (run_pg.php has both): it runs the work and counts the bracket. */
    public function transactional(callable $work): mixed
    {
        $this->transactions++;
        return $work();
    }

    /** A money value as the column stores it, or the PDOException PostgreSQL's overflow becomes. */
    private function column(mixed $value, string $column): string
    {
        $stored = hd_column($value);
        if ($stored === null) {
            throw new \PDOException("SQLSTATE[22003]: Numeric value out of range: 7 ERROR:  numeric field overflow (column {$column})");
        }
        return $stored;
    }

    public function getById(int $id): ?array
    {
        return $this->orders[$id] ?? null;
    }

    public function getOrderLines(int $orderId): array
    {
        return $this->lines[$orderId] ?? [];
    }

    public function calculateOrderTotal(int $orderId): float
    {
        return array_sum(array_map(static fn(array $l) => (float)$l['amount'], $this->lines[$orderId] ?? []));
    }

    public function getChangelog(int $orderId): array
    {
        return [];
    }

    public function create(array $data): int
    {
        $id = $this->nextOrderId++;
        $this->orders[$id] = [
            'id' => $id, 'application_id' => $data['application_id'], 'hospitality_id' => $data['hospitality_id'],
            'location_resource_id' => $data['location_resource_id'], 'status' => $data['status'],
            'comment' => $data['comment'] ?? null, 'special_requirements' => $data['special_requirements'] ?? null,
            'serving_time_iso' => self::toStorageTimestamp($data['serving_time_iso'] ?? null),
            'created' => '2026-11-24 22:00:00', 'modified' => '2026-11-24 22:00:00',
            'created_by' => $data['created_by'] ?? null, 'modified_by' => $data['created_by'] ?? null, 'billed' => 0,
            'hospitality_name' => 'Kjøkken', 'location_name' => 'Sal A',
        ];
        $this->lines[$id] = [];
        $this->writes[] = 'create';
        return $id;
    }

    public function update(int $id, array $data): bool
    {
        $this->orders[$id] = array_replace($this->orders[$id], $data, ['modified' => '2026-11-24 23:59:59']);
        $this->writes[] = 'update';
        return true;
    }

    public function addOrderLine(array $data): int
    {
        // As the real addOrderLine: amount = (float)quantity × (float)unit_price, and every value through its column.
        $amount = (float)($data['quantity'] ?? 1) * (float)$data['unit_price'];
        $row = [
            'quantity' => $this->column($data['quantity'] ?? 1, 'quantity'),
            'unit_price' => $this->column($data['unit_price'], 'unit_price'),
            'amount' => $this->column($amount, 'amount'),
        ];
        $id = $this->nextLineId++;
        $this->lines[(int)$data['order_id']][] = $row + [
            'id' => $id, 'order_id' => $data['order_id'], 'hospitality_article_id' => $data['hospitality_article_id'],
            'tax_code' => (int)$data['tax_code'], 'tax_percent' => TAX[(int)$data['tax_code']] ?? null,
            'comment' => $data['comment'] ?? null, 'article_name' => 'Art ' . $data['hospitality_article_id'], 'unit' => 'stk',
        ];
        $this->writes[] = 'addOrderLine';
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
        // As the real updateOrderLine: amount is recomputed only when quantity is in the body.
        foreach ($this->lines as $orderId => $rows) {
            foreach ($rows as $k => $line) {
                if ((int)$line['id'] !== $lineId) {
                    continue;
                }
                $new = $line;
                if (array_key_exists('quantity', $data)) {
                    $price = array_key_exists('unit_price', $data) ? $data['unit_price'] : $line['unit_price'];
                    $new['quantity'] = $this->column($data['quantity'], 'quantity');
                    $new['amount'] = $this->column((float)$data['quantity'] * (float)$price, 'amount');
                }
                if (array_key_exists('unit_price', $data)) {
                    $new['unit_price'] = $this->column($data['unit_price'], 'unit_price');
                }
                if (array_key_exists('tax_code', $data)) {
                    $new['tax_code'] = (int)$data['tax_code'];
                }
                if (array_key_exists('comment', $data)) {
                    $new['comment'] = $data['comment'];
                }
                $this->lines[$orderId][$k] = $new;
                $this->writes[] = 'updateOrderLine';
                return true;
            }
        }
        return true;
    }

    public function deleteOrderLine(int $lineId): bool
    {
        foreach ($this->lines as $orderId => $rows) {
            $this->lines[$orderId] = array_values(array_filter($rows, static fn(array $l) => (int)$l['id'] !== $lineId));
        }
        $this->writes[] = 'deleteOrderLine';
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

    /** One stored line, as the test database stores it. */
    public function plant(int $orderId, int $articleId, string $quantity, string $unitPrice, int $taxCode): void
    {
        $this->lines[$orderId][] = [
            'id' => $this->nextLineId++, 'order_id' => $orderId, 'hospitality_article_id' => $articleId, 'quantity' => $quantity,
            'unit_price' => $unitPrice, 'tax_code' => $taxCode, 'tax_percent' => TAX[$taxCode] ?? null,
            'amount' => bcround(bcmul($quantity, $unitPrice, 4), 2), 'comment' => null, 'article_name' => "Art {$articleId}", 'unit' => 'stk',
        ];
    }

    /** What a test compares: the order's own fields and every line, ids included. */
    public function snapshot(int $orderId): string
    {
        return json_encode([$this->orders[$orderId] ?? null, $this->lines[$orderId] ?? null]);
    }
}

/** The articles of hospitality 4 in the test database (measured 2026-11-24), plus the fixtures. The REAL resolveEffectivePricing runs on them. */
final class ArticleStub extends HospitalityArticleRepository
{
    public const ARTICLES = [
        5 => ['override_price' => null, 'override_tax_code' => null, 'base_price' => '125.00', 'base_tax_code' => 9],
        9 => ['override_price' => null, 'override_tax_code' => null, 'base_price' => '356.00', 'base_tax_code' => 9],
        11 => ['override_price' => '77.00', 'override_tax_code' => null, 'base_price' => '65.00', 'base_tax_code' => 12],
        19 => ['override_price' => '40.59', 'override_tax_code' => 0, 'base_price' => '25.00', 'base_tax_code' => 12],
        // the lane-5 fixture: 100.00 at the 15 % code
        21 => ['override_price' => '100.00', 'override_tax_code' => 15, 'base_price' => '25.00', 'base_tax_code' => 12],
        // a code fm_ecomva does not have: billing takes it as 0 %
        23 => ['override_price' => '100.00', 'override_tax_code' => 99, 'base_price' => '25.00', 'base_tax_code' => 12],
    ];

    public function __construct()
    {
    }

    public function getArticleById(int $id): ?array
    {
        $a = self::ARTICLES[$id] ?? null;
        return $a === null ? null : $a + ['id' => $id, 'hospitality_id' => 4, 'active' => 1, 'unit' => 'stk', 'article_name' => "Art {$id}"];
    }

    public function getTaxPercentByCode(): array
    {
        return TAX;
    }
}

final class HospitalityStub extends HospitalityRepository
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
        return [['id' => 7, 'name' => 'Sal A']];
    }

    public function getContact(int $hospitalityId, int $fallbackBuildingId = 0): array
    {
        return ['contact_info' => null, 'building_name' => 'Bygg', 'building_email' => null, 'building_phone' => null];
    }
}

final class AppServiceStub extends ApplicationService
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

final class AppHelperStub extends ApplicationHelper
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

final class UserStub extends UserHelper
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

final class NotifierSpy extends CateringChangeNotifier
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

// =====================================================================================================================
// Drivers
// =====================================================================================================================
const SERVING = '+30 days';
$application = [
    'id' => 85200, 'status' => 'ACCEPTED', 'id_string' => '85200', 'name' => 'Jubileum', 'building_id' => 10,
    'building_name' => 'Bygg', 'customer_organization_name' => null, 'case_officer_id' => 17, 'activity_id' => null,
];

/** A repo holding order 7 on application 85200, hospitality 4, comment «Før», and these stored lines [[article, q, price, code], …]. */
$newRepo = static function (array $stored = [[5, '3.00', '125.00', 9], [9, '2.00', '356.00', 9], [11, '1.00', '77.00', 12], [19, '4.00', '40.59', 0]]): ColumnRepo {
    $repo = new ColumnRepo();
    $repo->orders[7] = [
        'id' => 7, 'application_id' => 85200, 'hospitality_id' => 4, 'location_resource_id' => 7, 'status' => 'pending',
        'comment' => 'Før', 'special_requirements' => null, 'serving_time_iso' => gmdate('Y-m-d H:i:s', strtotime(SERVING)),
        'created' => '2026-11-20 10:00:00', 'modified' => '2026-11-20 10:00:00', 'created_by' => 77, 'modified_by' => 77,
        'billed' => 0, 'hospitality_name' => 'Kjøkken', 'location_name' => 'Sal A',
    ];
    $repo->lines[7] = [];
    foreach ($stored as [$article, $q, $p, $code]) {
        $repo->plant(7, $article, $q, $p, $code);
    }
    return $repo;
};

$citizenRef = new \ReflectionClass(CitizenOrderController::class);
$makeCitizen = static function (ColumnRepo $repo, NotifierSpy $notifier, array $app) use ($citizenRef): object {
    $service = new AppServiceStub();
    $service->application = $app;
    $user = new UserStub();
    $user->ssn = '01010112345';
    $c = $citizenRef->newInstanceWithoutConstructor();
    foreach ([
        'applicationHelper' => new AppHelperStub(), 'applicationService' => $service, 'userHelper' => $user,
        'hospitalityRepo' => new HospitalityStub(), 'articleRepo' => new ArticleStub(), 'orderRepo' => $repo,
        'guard' => new HospitalityOrderGuard(), 'notifier' => $notifier,
    ] as $prop => $value) {
        $citizenRef->getProperty($prop)->setValue($c, $value);
    }
    return $c;
};

$adminRef = new \ReflectionClass(AdminOrderController::class);
$makeAdmin = static function (ColumnRepo $repo) use ($adminRef): object {
    $c = $adminRef->newInstanceWithoutConstructor();
    $adminRef->getProperty('repository')->setValue($c, $repo);
    $adminRef->getProperty('articleRepository')->setValue($c, new ArticleStub());
    $adminRef->getProperty('userSettings')->setValue($c, ['account_id' => 5]);
    $adminRef->getProperty('acl')->setValue($c, new class extends \App\modules\phpgwapi\security\Acl {
        public function __construct()
        {
        }

        public function check($location, $required, $appname = '')
        {
            return true;
        }
    });
    return $c;
};

/** @return array{status: ?int, json: mixed, body: string, threw: ?string} */
$invoke = static function (object $controller, string $handler, string $verb, array $args, string $json): array {
    try {
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest($verb, 'https://pe-api.test/x')
            ->withBody((new \Slim\Psr7\Factory\StreamFactory())->createStream($json))
            ->withHeader('Content-Type', 'application/json');
        $response = $controller->$handler($request, new \Slim\Psr7\Response(), $args);
        $body = (string)$response->getBody();
        return ['status' => $response->getStatusCode(), 'json' => json_decode($body, true), 'body' => $body, 'threw' => null];
    } catch (\Throwable $e) {
        return ['status' => null, 'json' => null, 'body' => '', 'threw' => get_class($e) . ': ' . $e->getMessage()];
    }
};

/** POST lines [[article, quantity], …]. Returns [result, repo, notifier]. */
$create = static function (array $lines, array $app = []) use ($newRepo, $makeCitizen, $invoke, $application): array {
    $repo = $newRepo();
    $notifier = new NotifierSpy();
    $body = json_encode([
        'hospitality_id' => 4, 'location_resource_id' => 7, 'serving_time_iso' => gmdate('c', strtotime(SERVING)), 'comment' => 'Ny',
        'lines' => array_map(static fn(array $l) => ['hospitality_article_id' => $l[0], 'quantity' => $l[1]], $lines),
    ]);
    return [$invoke($makeCitizen($repo, $notifier, array_replace($application, $app)), 'createOrder', 'POST', ['id' => 85200], $body), $repo, $notifier];
};
/** PUT order 7 with this body (lines as [[article, quantity], …] or absent). Returns [result, repo, notifier, snapshot before]. */
$update = static function (array $body, array $app = [], ?array $stored = null) use ($newRepo, $makeCitizen, $invoke, $application): array {
    $repo = $stored === null ? $newRepo() : $newRepo($stored);
    $before = $repo->snapshot(7);
    $notifier = new NotifierSpy();
    if (isset($body['lines'])) {
        $body['lines'] = array_map(static fn(array $l) => ['hospitality_article_id' => $l[0], 'quantity' => $l[1]], $body['lines']);
    }
    return [$invoke($makeCitizen($repo, $notifier, array_replace($application, $app)), 'updateOrder', 'PUT', ['id' => 85200, 'orderId' => 7], json_encode($body)), $repo, $notifier, $before];
};
/** What a refusal must leave: no write at all, no case entry, no mail. */
$nothing = static fn(ColumnRepo $repo, NotifierSpy $n): array => ['writes' => $repo->writes, 'entries' => count($repo->entries), 'mails' => count($n->payloads)];
$none = ['writes' => [], 'entries' => 0, 'mails' => 0];
$refusal = static fn(array $r): array => [$r['status'], $r['json']['code'] ?? null, $r['json']['error'] ?? null, $r['json']['line'] ?? null, $r['threw']];
$lineTuples = static fn(ColumnRepo $repo, int $orderId): array => array_map(
    static fn(array $l) => [(int)$l['hospitality_article_id'], $l['quantity'], $l['unit_price'], (int)$l['tax_code'], $l['amount']],
    $repo->lines[$orderId] ?? []
);

// =====================================================================================================================
// A. HospitalityOrderAmount on its own
// =====================================================================================================================
$cls = '\\App\\modules\\booking\\services\\HospitalityOrderAmount';
$haveClass = class_exists($cls, false);
$check('A: HospitalityOrderAmount is loaded from this tree', $haveClass, "not under {$root}");
if ($haveClass) {
    $same('A: MAX_CENTS is 99,999,999.99 in øre', $cls::MAX_CENTS, 9999999999);
    $same('A: the codes', [$cls::CODE_LINE, $cls::CODE_TOTAL], ['amount_too_large', 'order_total_too_large']);

    // [value, øre as the column stores it]. Strings by their digits; an int, and a float as the string PDO binds.
    foreach ([
        ['40.59', 4059], [40.59, 4059], ['99999999.99', 9999999999], ['99999999.994', 9999999999], ['99999999.995', 10000000000],
        ['100000000', 10000000000], ['1e8', 10000000000], [' 1e8 ', 10000000000], [1e8, 10000000000], ['1.005', 101], ['-1.005', -101],
        ['0.004', 0], ['0.005', 1], ['-0.005', -1], ['.5', 50], ['5.', 500], ['0', 0], ['-0.00', 0], [7, 700], ['007.10', 710],
        ['1.23e2', 12300], ['1.2345E1', 1235], ['5e-3', 1], ['4.9e-3', 0], ['1e-400', 0],
        // PHP's 2463661 * 40.59 is 99999999.99000001: what PDO binds is "99999999.99", and so is what is judged.
        [2463661 * 40.59, 9999999999],
        ['abc', null], ['', null], ['NaN', null], ['Infinity', null], ['0x1A', null], ['1,5', null], [NAN, null], [INF, null],
        [null, null], [true, null], [[1], null],
    ] as [$value, $want]) {
        $same('A: hundredths(' . var_export($value, true) . ')', $cls::hundredths($value), $want);
    }
    // Far beyond the column: capped, never wrapped into a small or negative int.
    foreach (['1e400', '-1e400', 1e30, '123456789012345678901234567890', -1e30] as $huge) {
        $h = $cls::hundredths($huge);
        $check('A: hundredths(' . var_export($huge, true) . ') is beyond the column, with its sign', is_int($h) && abs($h) > $cls::MAX_CENTS && ($h > 0) === (str_starts_with((string)$huge, '-') === false), var_export($h, true));
    }

    // line(): [quantity, price, code] -> [net, tax] in øre; the oracle is billing's formula in exact decimals.
    foreach ([
        'C4: 2,463,661 × 40.59 at 0 %' => ['2463661', '40.59', 0],
        'lane 4: 639,999 × 125.00 at 25 %' => ['639999', '125.00', 9],
        'lane 5: 869,565 × 100.00 at 15 %' => ['869565', '100.00', 15],
        'override 77.00 × 1,100,000 at code 12 (25 %)' => ['1100000', '77.00', 12],
        'an unknown code (99) is 0 %, as billing' => ['3', '100.00', 99],
        'half an øre of VAT rounds UP: 1 × 0.02 at 25 % (0.005)' => ['1', '0.02', 9],
        'half an øre: 3 × 0.10 at 25 % (0.075)' => ['3', '0.10', 9],
        'half an øre: 1 × 0.10 at 15 % (0.015)' => ['1', '0.10', 15],
        'a negative price rounds away from zero: 1 × -0.02 at 25 %' => ['1', '-0.02', 9],
        'a stored fractional quantity: 2.50 × 0.03 at 25 % (net 0.075, tax 0.01875)' => ['2.50', '0.03', 9],
        'VAT is taken of the UNROUNDED net: 0.50 × 0.05 at 25 % (net 0.025, tax 0.00625)' => ['0.50', '0.05', 9],
    ] as $label => [$q, $p, $code]) {
        [$net, $tax] = hd_billing_line($q, $p, $code);
        $same("A: line(), {$label}", $cls::line($q, $p, $code, TAX), ['net' => (int)bcmul($net, '100', 0), 'tax' => (int)bcmul($tax, '100', 0)]);
    }
    $same('A: line() with a NULL tax code is fm_ecomva code 0 (0 %), as billing ((int) null)', $cls::line(2, '10.00', null, TAX), ['net' => 2000, 'tax' => 0]);
    $same('A: line() of a word is not an amount', $cls::line(2, 'abc', 9, TAX), null);
    $same('A: line() with an int quantity and a float price (the applicant path) equals the stored strings', $cls::line(639999, 125.0, 9, TAX), $cls::line('639999.00', '125.00', 9, TAX));

    // gross(): against the oracle
    $c6 = [['799999', '125.00', 9], ['280898', '356.00', 9]];
    $same('A: gross() of C6 = 249,999,453.75', $cls::gross(array_map(static fn($l) => ['quantity' => $l[0], 'unit_price' => $l[1], 'tax_code' => $l[2]], $c6), TAX), hd_gross_ore($c6));
    $same('A: ...and the oracle agrees it is 249,999,453.75', hd_gross_ore($c6), 24999945375);

    // refusal(): lines as the controllers hand them
    $L = static fn(string $q, string $p, ?int $c): array => ['quantity' => $q, 'unit_price' => $p, 'tax_code' => $c];
    $verdict = static fn(array $written, ?array $after = null, array $before = []) => $cls::refusal($written, $after ?? $written, $before, TAX);
    $same('A lane 3: 2,463,661 × 40.59 at 0 % = 99,999,999.99 fits (a float compare would refuse it)', $verdict([$L('2463661', '40.59', 0)]), null);
    $same('A lane 3: 2,463,662 × 40.59 = 100,000,040.58: the line', $verdict([$L('2463662', '40.59', 0)]), ['code' => 'amount_too_large', 'line' => 0]);
    $same('A lane 4: 639,999 × 125.00 at 25 %: gross 99,999,843.75 fits', $verdict([$L('639999', '125.00', 9)]), null);
    $same('A lane 4: 640,000 × 125.00: net 80,000,000 fits the line, gross 100,000,000.00 does not', $verdict([$L('640000', '125.00', 9)]), ['code' => 'order_total_too_large', 'line' => null]);
    $same('A lane 5: 869,565 × 100.00 at 15 %: gross 99,999,975.00 fits', $verdict([$L('869565', '100.00', 15)]), null);
    $same('A lane 5: 869,566 × 100.00 at 15 %: gross 100,000,090.00', $verdict([$L('869566', '100.00', 15)]), ['code' => 'order_total_too_large', 'line' => null]);
    $same('A lane 6: 1,100,000 × 77.00 at 25 %: gross 105,875,000', $verdict([$L('1100000', '77.00', 12)]), ['code' => 'order_total_too_large', 'line' => null]);
    $same('A lane 6 control: at the base price 65.00 it would fit (89,375,000)', $verdict([$L('1100000', '65.00', 12)]), null);
    $same('A lane 8: C6 (each line fits, the gross does not)', $verdict([$L('799999', '125.00', 9), $L('280898', '356.00', 9)]), ['code' => 'order_total_too_large', 'line' => null]);
    $same('A: an unknown code is 0 %: 999,999 × 100.00 at code 99 fits (gross = net 99,999,900)', $verdict([$L('999999', '100.00', 99)]), null);
    $same('A: the line named is the FIRST that does not fit, by the request\'s own key', $verdict(['a' => $L('1', '1.00', 0), 'b' => $L('800000', '125.00', 9), 'c' => $L('900000', '125.00', 9)]), ['code' => 'amount_too_large', 'line' => 'b']);
    $same('A: a unit price beyond the column is the line even when the net fits (0.50 × 150,000,000.00)', $verdict([$L('0.50', '150000000.00', 0)]), ['code' => 'amount_too_large', 'line' => 0]);
    $same('A: a negative line beyond the column is the line too (numeric(10,2) is symmetric)', $verdict([$L('1', '-100000000.00', 0)]), ['code' => 'amount_too_large', 'line' => 0]);
    $same('A: a negative gross beyond the column is the total', $verdict([$L('1', '-60000000.00', 0), $L('1', '-60000000.00', 0)]), ['code' => 'order_total_too_large', 'line' => null]);
    $same('A: a price that is not a number is the database\'s to refuse, not an amount', $verdict([$L('1', 'abc', 0)]), null);

    // The «not greater than the current gross» clause: an order already over the bound may come DOWN, never go up.
    // Over the bound with room in each line: 5 × 700,000 + 9 × 200,000, gross 198,375,000.00.
    $overAfter = static fn(string $q9): array => [$L('700000', '125.00', 9), $L($q9, '356.00', 9)];
    $overBefore = $overAfter('200000');
    $same('A lowering: an over-bound order may come down (200,000 -> 199,000)', $cls::refusal([1 => $overAfter('199000')[1]], $overAfter('199000'), $overBefore, TAX), null);
    $same('A lowering: ...and stay where it is (an unchanged line)', $cls::refusal([1 => $overBefore[1]], $overBefore, $overBefore, TAX), null);
    $same('A lowering: ...but not go up by one unit (200,000 -> 200,001)', $cls::refusal([1 => $overAfter('200001')[1]], $overAfter('200001'), $overBefore, TAX), ['code' => 'order_total_too_large', 'line' => null]);
    $same('A lowering: a NEW order has nothing to come down from', $cls::refusal($overBefore, $overBefore, [], TAX), ['code' => 'order_total_too_large', 'line' => null]);
    $c6Before = [$L('799999', '125.00', 9), $L('280898', '356.00', 9)];
    $same('A lowering: raising a line past ITS bound names the line, whatever the order', $cls::refusal([1 => $L('280899', '356.00', 9)], [$c6Before[0], $L('280899', '356.00', 9)], $c6Before, TAX), ['code' => 'amount_too_large', 'line' => 1]);
    $same('A lowering: the line bound has no such clause (a line beyond the column cannot be stored at all)', $cls::refusal([$L('800000', '125.00', 9)], [$L('800000', '125.00', 9)], [$L('900000', '125.00', 9)], TAX), ['code' => 'amount_too_large', 'line' => 0]);
}

// =====================================================================================================================
// B. The applicant's createOrder / updateOrder
// =====================================================================================================================
$lineText = '!catering_amount_too_large';
$totalText = '!catering_order_total_too_large';

// --- lane 1: POST with a line no column holds -> 422 naming it, and NO order row (base: 500 + an orphan) ---
[$r, $repo, $n] = $create([[5, 800000]]);
$same('B lane 1: POST [5×800000] -> 422, amount_too_large, the translated text, line 0', $refusal($r), [422, 'amount_too_large', $lineText, 0, null]);
$same('B lane 1: ...and nothing written: no order, no line, no case entry, no mail', $nothing($repo, $n), $none);
$same('B lane 1: ...no order row at all (base: an orphan order with 0 lines)', array_keys($repo->orders), [7]);
[$r, $repo, $n] = $create([[9, 1], [5, 800000]]);
$same('B lane 1: POST [9×1, 5×800000] -> 422 naming line 1', $refusal($r), [422, 'amount_too_large', $lineText, 1, null]);
$same('B lane 1: ...and the good line before it was not written either (base: an order holding it)', [$nothing($repo, $n), array_keys($repo->orders)], [$none, [7]]);

// --- lane 2: PUT with an overflowing line -> 422, the order exactly as it was (base: comment written, lines deleted) ---
[$r, $repo, $n, $before] = $update(['comment' => 'Etter', 'lines' => [[5, 800000]]]);
$same('B lane 2: PUT [5×800000] on a 4-line order -> 422 line 0', $refusal($r), [422, 'amount_too_large', $lineText, 0, null]);
$same('B lane 2: ...nothing written: comment, modified and the 4 lines unchanged', [$nothing($repo, $n), $repo->snapshot(7) === $before], [$none, true]);
[$r, $repo, $n, $before] = $update(['comment' => 'Etter', 'lines' => [[9, 6], [5, 800000], [9, 7]]]);
$same('B lane 2: PUT with the overflow MID-list -> 422 naming line 1', $refusal($r), [422, 'amount_too_large', $lineText, 1, null]);
$same('B lane 2: ...and the order is as it was (base: only the line before it stored)', [$nothing($repo, $n), $repo->snapshot(7) === $before], [$none, true]);
[$r, $repo, $n, $before] = $update(['comment' => 'Etter', 'lines' => [[999, 1]]]);
$same('B lane 2: PUT comment + an unknown article -> the article refusal (400), as before', [$r['status'], $r['json']['error'] ?? null], [400, 'Line 0: article not found']);
$same('B lane 2: ...and the comment is NOT written (47994 RECORD; base wrote it before the line checks)', [$nothing($repo, $n), $repo->orders[7]['comment']], [$none, 'Før']);
[$r, $repo, $n, $before] = $update(['comment' => 'Etter', 'lines' => []]);
$same('B lane 2: PUT comment + an EMPTY lines array -> 400, and the comment is not written either', [$r['status'], $r['json']['error'] ?? null, $nothing($repo, $n), $repo->orders[7]['comment']], [400, 'lines array must not be empty', $none, 'Før']);

// --- lane 3: the exact limit at 0 % ---
[$r, $repo, $n] = $create([[19, 2463661]]);
$same('B lane 3: 2,463,661 × 40.59 = 99,999,999.99 -> 201, stored as such', [$r['status'], $lineTuples($repo, 300)], [201, [[19, '2463661.00', '40.59', 0, '99999999.99']]]);
[$r, $repo, $n] = $create([[19, 2463662]]);
$same('B lane 3: 2,463,662 × 40.59 -> 422 line 0, nothing written', [$refusal($r), $nothing($repo, $n)], [[422, 'amount_too_large', $lineText, 0, null], $none]);

// --- lane 4: 25 %, the GROSS ---
[$r, $repo, $n] = $create([[5, 639999]]);
$same('B lane 4: 639,999 × 125.00 at 25 % (gross 99,999,843.75) -> 201', [$r['status'], $lineTuples($repo, 300)], [201, [[5, '639999.00', '125.00', 9, '79999875.00']]]);
[$r, $repo, $n] = $create([[5, 640000]]);
$same('B lane 4: 640,000 × 125.00 (net 80,000,000 fits, gross 100,000,000.00) -> 422 order_total_too_large, no line named', $refusal($r), [422, 'order_total_too_large', $totalText, null, null]);
$same('B lane 4: ...nothing written (base: 201, an order whose bill overflows bb_payment.amount)', $nothing($repo, $n), $none);

// --- lane 5: 15 % (the fixture code) ---
[$r, $repo, $n] = $create([[21, 869565]]);
$same('B lane 5: 869,565 × 100.00 at 15 % (gross 99,999,975.00) -> 201', [$r['status'], $lineTuples($repo, 300)], [201, [[21, '869565.00', '100.00', 15, '86956500.00']]]);
[$r, $repo, $n] = $create([[21, 869566]]);
$same('B lane 5: 869,566 × 100.00 at 15 % -> 422 total, nothing written', [$refusal($r), $nothing($repo, $n)], [[422, 'order_total_too_large', $totalText, null, null], $none]);

// --- lane 6: the override price is what is judged ---
[$r, $repo, $n] = $create([[11, 1100000]]);
$same('B lane 6: article 11 (77.00 over 65.00) × 1,100,000 -> 422 total (with 77.00: gross 105,875,000)', [$refusal($r), $nothing($repo, $n)], [[422, 'order_total_too_large', $totalText, null, null], $none]);
[$r, $repo, $n] = $create([[11, 1000000]]);
$same('B lane 6 control: × 1,000,000 (gross 96,250,000) -> 201, stored at 77.00 and code 12', [$r['status'], $lineTuples($repo, 300)], [201, [[11, '1000000.00', '77.00', 12, '77000000.00']]]);

// --- lane 7: a mixed order, 0 / 15 / 25 % and an override ---
$mixed = [[19, 1000000], [21, 300000], [11, 200000], [5, 36223]];
$same('B lane 7: the oracle puts the mixed order at 99,999,843.75', hd_gross_ore([['1000000', '40.59', 0], ['300000', '100.00', 15], ['200000', '77.00', 12], ['36223', '125.00', 9]]), 9999984375);
[$r, $repo, $n] = $create($mixed);
$same('B lane 7: mixed, gross 99,999,843.75 -> 201 with all four lines', [$r['status'], count($repo->lines[300] ?? [])], [201, 4]);
[$r, $repo, $n] = $create([[19, 1000000], [21, 300000], [11, 200000], [5, 36224]]);
$same('B lane 7: one unit more (gross 100,000,000.00) -> 422 total, nothing written', [$refusal($r), $nothing($repo, $n)], [[422, 'order_total_too_large', $totalText, null, null], $none]);

// --- lane 8: every line fits, the order does not ---
[$r, $repo, $n] = $create([[5, 799999], [9, 280898]]);
$same('B lane 8: C6 [5×799,999, 9×280,898] (gross 249,999,453.75) -> 422 total (base: 201)', [$refusal($r), $nothing($repo, $n)], [[422, 'order_total_too_large', $totalText, null, null], $none]);
[$r, $repo, $n, $before] = $update(['lines' => [[5, 799999], [9, 280898]]]);
$same('B lane 8: the same lines as a PUT -> 422 total, the order as it was', [$refusal($r), $nothing($repo, $n), $repo->snapshot(7) === $before], [[422, 'order_total_too_large', $totalText, null, null], $none, true]);

// The «not greater than the current gross» clause on the applicant's PUT: an over-bound order may come down.
// Over the bound with room in each line: 5 × 700,000 + 9 × 200,000 (gross 198,375,000.00), as C6-like data stored before this change.
$overStored = [[5, '700000.00', '125.00', 9], [9, '200000.00', '356.00', 9]];
[$r, $repo, $n] = $update(['lines' => [[5, 700000], [9, 199000]]], [], $overStored);
$same('B: PUT that LOWERS an over-bound order -> 200, the new lines stored', [$r['status'], $lineTuples($repo, 7)], [200, [[5, '700000.00', '125.00', 9, '87500000.00'], [9, '199000.00', '356.00', 9, '70844000.00']]]);
[$r, $repo, $n, $before] = $update(['lines' => [[5, 700000], [9, 200001]]], [], $overStored);
$same('B: PUT that RAISES it -> 422 total, the order as it was', [$refusal($r), $repo->snapshot(7) === $before], [[422, 'order_total_too_large', $totalText, null, null], true]);

// --- lane 11: normal orders are unchanged ---
foreach ([
    'small' => [[[5, 2]], [[5, '2.00', '125.00', 9, '250.00']]],
    'large, legitimate (10,000 × 356.00)' => [[[9, 10000]], [[9, '10000.00', '356.00', 9, '3560000.00']]],
    'mixed' => [[[19, 3], [21, 2], [11, 4], [5, 1]], [[19, '3.00', '40.59', 0, '121.77'], [21, '2.00', '100.00', 15, '200.00'], [11, '4.00', '77.00', 12, '308.00'], [5, '1.00', '125.00', 9, '125.00']]],
    'override' => [[[11, 10]], [[11, '10.00', '77.00', 12, '770.00']]],
] as $label => [$lines, $want]) {
    [$r, $repo, $n] = $create($lines);
    $same("B lane 11: create {$label} -> 201 and exactly these lines", [$r['status'], $lineTuples($repo, 300)], [201, $want]);
    $same("B lane 11: create {$label} on a submitted application -> one officer mail, as before", count($n->payloads), 1);
    [$r, $repo, $n] = $update(['comment' => 'Etter', 'lines' => $lines]);
    $same("B lane 11: PUT {$label} -> 200, the comment and these lines", [$r['status'], $repo->orders[7]['comment'], $lineTuples($repo, 7)], [200, 'Etter', $want]);
}
[$r, $repo, $n] = $update(['comment' => 'Bare kommentar'], [], $overStored);
$same('B lane 11: a comment-only PUT on an order OVER the bound -> 200, comment saved, its 2 lines untouched', [$r['status'], $repo->orders[7]['comment'], count($repo->lines[7])], [200, 'Bare kommentar', 2]);
[$r, $repo, $n] = $create([[5, 2]], ['status' => HospitalityOrderGuard::DRAFT_STATUS]);
$same('B lane 11: a draft (cart) create -> 201, a «created» changelog entry, no mail', [$r['status'], $repo->entries[0]['change_type'] ?? null, count($n->payloads)], [201, 'created', 0]);
$same('B (b): the create ran as ONE transactional() bracket', $repo->transactions, 1);
[$r, $repo, $n] = $update(['comment' => 'Etter', 'lines' => [[5, 2]]]);
$same('B (b): the update ran as ONE transactional() bracket', $repo->transactions, 1);

// --- lane 12: the refusals that were there keep their place ---
[$r, $repo, $n] = $create([[5, 800000]], ['status' => 'REJECTED']);
$same('B lane 12: a closed application + an overflow -> the deadline guard (409), not 422', [$r['status'], $r['json']['code'] ?? null, $nothing($repo, $n)], [409, HospitalityOrderGuard::REASON_APPLICATION_CLOSED, $none]);
[$r, $repo, $n] = $update(['comment' => 'Etter', 'lines' => [[5, 800000]]], ['status' => 'REJECTED']);
$same('B lane 12: ...the same on a PUT', [$r['status'], $r['json']['code'] ?? null, $nothing($repo, $n)], [409, HospitalityOrderGuard::REASON_APPLICATION_CLOSED, $none]);
[$r, $repo, $n] = $create([[5, 800000], [5, 2.5]]);
$same('B lane 12: a fractional quantity is refused (400) before the amount, even on a later line', [$r['status'], $r['json']['code'] ?? null, $r['json']['line'] ?? null], [400, 'quantity_not_whole', 1]);
[$r, $repo, $n] = $update(['lines' => [[5, 800000], [5, 2.5]]]);
$same('B lane 12: ...the same on a PUT', [$r['status'], $r['json']['code'] ?? null, $r['json']['line'] ?? null], [400, 'quantity_not_whole', 1]);
[$r, $repo, $n] = $create([[5, 800000], [999, 1]]);
$same('B lane 12: an unknown article is refused (400) before the amount', [$r['status'], $r['json']['error'] ?? null], [400, 'Line 1: article not found']);
[$r, $repo, $n] = $update(['location_resource_id' => 99, 'lines' => [[5, 800000]]]);
$same('B lane 12: an invalid delivery location is refused (400) before the amount', [$r['status'], $r['json']['error'] ?? null, $nothing($repo, $n)], [400, 'Invalid delivery location', $none]);

// --- lane 13: a refused overflow tells nobody ---
foreach (['create' => fn() => $create([[5, 800000]]), 'update' => fn() => $update(['comment' => 'Etter', 'lines' => [[5, 640000]]])] as $label => $go) {
    [$r, $repo, $n] = $go();
    $same("B lane 13: a refused overflow ({$label}) on a SUBMITTED application: 422, no case entry, no mail", [$r['status'], count($repo->entries), count($n->payloads)], [422, 0, 0]);
}

// =====================================================================================================================
// C. The admin's addLine / updateLine (lane 9)
// =====================================================================================================================
$adminLine = '!booking.hospitality_order_amount_too_large';
$adminTotal = '!booking.hospitality_order_total_too_large';
$addLine = static function (array $body, ?array $stored = null) use ($newRepo, $makeAdmin, $invoke): array {
    $repo = $stored === null ? $newRepo() : $newRepo($stored);
    $before = $repo->snapshot(7);
    return [$invoke($makeAdmin($repo), 'addLine', 'POST', ['id' => 7], json_encode(['changelog_comment' => 'Rettet'] + $body)), $repo, $before];
};
/** PUT the line at position $pos of order 7. */
$updateLine = static function (array $body, int $pos = 0, ?array $stored = null) use ($newRepo, $makeAdmin, $invoke): array {
    $repo = $stored === null ? $newRepo() : $newRepo($stored);
    $before = $repo->snapshot(7);
    $lineId = (int)$repo->lines[7][$pos]['id'];
    return [$invoke($makeAdmin($repo), 'updateLine', 'PUT', ['id' => 7, 'lineId' => $lineId], json_encode(['changelog_comment' => 'Rettet'] + $body)), $repo, $before];
};
$adminRefusal = static fn(array $r): array => [$r['status'], $r['json'], $r['threw']];

[$r, $repo, $before] = $addLine(['hospitality_article_id' => 5, 'quantity' => 800000]);
$same('C lane 9: addLine 5 × 800,000 -> 422, the translated text and the code (base: 500 with the raw SQLSTATE)', $adminRefusal($r), [422, ['error' => $adminLine, 'code' => 'amount_too_large'], null]);
$same('C lane 9: ...nothing written, no changelog entry', [$repo->writes, count($repo->entries), $repo->snapshot(7) === $before], [[], 0, true]);
[$r, $repo, $before] = $updateLine(['quantity' => 800000]);
$same('C lane 9: updateLine (125.00) to 800,000 -> 422 line, nothing written', [$adminRefusal($r), $repo->writes, $repo->snapshot(7) === $before], [[422, ['error' => $adminLine, 'code' => 'amount_too_large'], null], [], true]);
[$r, $repo, $before] = $updateLine(['unit_price' => '100000000']);
$same('C lane 9: updateLine with a body unit_price of 100,000,000 -> 422 line, nothing written', [$adminRefusal($r), $repo->writes, $repo->snapshot(7) === $before], [[422, ['error' => $adminLine, 'code' => 'amount_too_large'], null], [], true]);
[$r, $repo, $before] = $updateLine(['unit_price' => '40000000.00']);
$same('C lane 9: a body unit_price ALONE that fits its column but not 3 × it (120,000,000: what billing bills) -> 422 line', [$adminRefusal($r), $repo->writes], [[422, ['error' => $adminLine, 'code' => 'amount_too_large'], null], []]);
[$r, $repo, $before] = $updateLine(['tax_code' => 9, 'quantity' => 640000]);
$same('C lane 9: a body tax_code is judged as it will be stored (640,000 × 125.00 at 25 %: the total)', [$adminRefusal($r), $repo->writes], [[422, ['error' => $adminTotal, 'code' => 'order_total_too_large'], null], []]);
[$r, $repo] = $updateLine(['tax_code' => 0, 'quantity' => 640000], 0, [[5, '3.00', '125.00', 9]]);
$same('C lane 9: ...and the same quantity at a 0 % code fits (net 80,000,000)', [$r['status'], $repo->writes], [200, ['updateOrderLine']]);

// lowering an order already over the bound, one line at a time (as the admin page saves)
[$r, $repo] = $updateLine(['quantity' => 199000], 1, $overStored);
$same('C lane 9: on an over-bound order, LOWERING a line -> 200 and written (a strict-total rule would refuse it)', [$r['status'], $repo->writes, $repo->lines[7][1]['quantity']], [200, ['updateOrderLine'], '199000.00']);
[$r, $repo, $before] = $updateLine(['quantity' => 200001], 1, $overStored);
$same('C lane 9: ...RAISING it -> 422 total, nothing written', [$adminRefusal($r), $repo->writes, $repo->snapshot(7) === $before], [[422, ['error' => $adminTotal, 'code' => 'order_total_too_large'], null], [], true]);
[$r, $repo, $before] = $addLine(['hospitality_article_id' => 9, 'quantity' => 1], $overStored);
$same('C lane 9: ...ADDING any line to it -> 422 total, nothing written', [$adminRefusal($r), $repo->writes], [[422, ['error' => $adminTotal, 'code' => 'order_total_too_large'], null], []]);
[$r, $repo] = $updateLine(['comment' => 'Uten løk'], 1, $overStored);
$same('C lane 9: ...a comment-only edit is not judged -> 200', [$r['status'], $repo->writes], [200, ['updateOrderLine']]);
// Base's unit-price-only update stores 3 × 40,000,000.00 (its amount is not recomputed): billing would bill 120,000,000.00.
// A comment on that line changes no amount, so it is not judged (judging it would lock the line's comment for good).
[$r, $repo] = $updateLine(['comment' => 'Uten løk'], 0, [[5, '3.00', '40000000.00', 0]]);
$same('C lane 9: a comment-only edit of a line ALREADY beyond the bound (3 × 40,000,000.00, stored by base) -> 200', [$r['status'], $repo->writes], [200, ['updateOrderLine']]);
[$r, $repo] = $updateLine(['quantity' => 2], 0, [[5, '3.00', '40000000.00', 0]]);
$same('C lane 9: ...and lowering it to 2 × 40,000,000.00 (80,000,000.00) -> 200', [$r['status'], $repo->writes], [200, ['updateOrderLine']]);
[$r, $repo] = $addLine(['hospitality_article_id' => 5, 'quantity' => 639999], []);
$same('C lane 9: addLine at the edge (639,999 × 125.00 on an empty order, gross 99,999,843.75) -> 201', [$r['status'], $repo->writes], [201, ['addOrderLine']]);
[$r, $repo, $before] = $addLine(['hospitality_article_id' => 5, 'quantity' => 640000], []);
$same('C lane 9: ...one more -> 422 total', [$adminRefusal($r), $repo->writes], [[422, ['error' => $adminTotal, 'code' => 'order_total_too_large'], null], []]);
[$r, $repo, $before] = $addLine(['hospitality_article_id' => 5, 'quantity' => 2.5]);
$same('C lane 9: the quantity refusal (400) still comes first', [$r['status'], $repo->writes], [400, []]);
[$r, $repo] = $addLine(['hospitality_article_id' => 9, 'quantity' => 2]);
$same('C lane 11: a normal addLine -> 201, one line, one changelog entry, as before', [$r['status'], $repo->writes, count($repo->entries)], [201, ['addOrderLine'], 1]);
[$r, $repo] = $updateLine(['quantity' => 5]);
$same('C lane 11: a normal updateLine -> 200, the line updated, one changelog entry', [$r['status'], $repo->writes, $repo->lines[7][0]['quantity'], count($repo->entries)], [200, ['updateOrderLine'], '5.00', 1]);

// =====================================================================================================================
// D. The translations (lane 15): exactly one non-empty row per language, with pe-pm's wording (49636)
// =====================================================================================================================
$texts = [
    'no' => ['Beløpet for én av linjene er for stort.', 'Totalbeløpet for bestillingen er for stort.'],
    'nn' => ['Beløpet for éi av linjene er for stort.', 'Totalbeløpet for bestillinga er for stort.'],
    'en' => ['The amount for one of the lines is too large.', 'The order total is too large.'],
];
foreach (['bookingfrontend' => ['catering_amount_too_large', 'catering_order_total_too_large'], 'booking' => ['hospitality_order_amount_too_large', 'hospitality_order_total_too_large']] as $module => $keys) {
    foreach ($texts as $langCode => $want) {
        $rows = explode("\n", (string)@file_get_contents("{$root}/src/modules/{$module}/setup/phpgw_{$langCode}.lang"));
        foreach ($keys as $i => $key) {
            $hits = array_values(preg_grep('/^' . preg_quote($key, '/') . "\t{$module}\t{$langCode}\t/", $rows));
            $same("D lane 15: {$module}.{$key} [{$langCode}] is there once, with the wording", $hits, ["{$key}\t{$module}\t{$langCode}\t{$want[$i]}"]);
        }
    }
}

// =====================================================================================================================
@unlink($logFile);
$total = $passed + count($failures);
if ($failures) {
    foreach ($failures as $f) {
        echo 'FAIL ', $f, "\n";
    }
    echo 'Amount harness: FAIL ', count($failures), " of {$total} checks\n";
    exit(1);
}
echo "Amount harness: PASS {$total} checks on PHP ", PHP_VERSION, "\n";
