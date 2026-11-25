<?php

/**
 * Hermetic rows for «lock what was ordered» (operator ruling D5 = a, forum/pe-general/50372), on the applicant's updateOrder.
 *
 *   php tests/catering-keep-price/run.php
 *
 * The REAL controller, the REAL HospitalityArticleRepository::resolveEffectivePricing() (only its getArticleById() and
 * getTaxPercentByCode() are the database, stubbed) and the REAL HospitalityOrderDiff. No database, no mail, no HTTP.
 *
 * The rule under test: a line the order already holds, matched by article id, keeps the unit price and VAT it was ordered
 * at, whatever the menu says now. Only a NEW line takes today's menu price and VAT.
 *
 * Environment (optional): HD_ROOT = tree holding src/ (default: this repo); VENDOR_AUTOLOAD = composer autoload.
 * Exit code: 0 all pass, 1 any failure.
 */

date_default_timezone_set('Europe/Oslo');
error_reporting(E_ALL & ~E_DEPRECATED);

$root = getenv('HD_ROOT') ?: dirname(__DIR__, 2);
require_once getenv('VENDOR_AUTOLOAD') ?: $root . '/vendor/autoload.php';

// The stubs' parents reach the legacy includes through these constants; they point at THIS repo, never at HD_ROOT.
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
    'modules/booking/services/HospitalityOrderAmount.php',
    'modules/booking/services/CateringText.php',
    'modules/booking/services/CateringLangSwitch.php',
    'modules/booking/services/CateringMailRenderer.php',
    'modules/booking/services/CateringChangeText.php',
    'modules/booking/services/TranslationLangSwitch.php',
    'modules/booking/repositories/HospitalityOrderRepository.php',
    'modules/bookingfrontend/services/applications/CateringChangeNotifier.php',
    'modules/bookingfrontend/services/applications/CitizenHospitalityOrder.php',
    'modules/bookingfrontend/controllers/applications/HospitalityOrderController.php',
] as $file) {
    require_once $root . '/src/' . $file;
}

use App\modules\booking\repositories\HospitalityArticleRepository;
use App\modules\booking\repositories\HospitalityOrderRepository;
use App\modules\booking\repositories\HospitalityRepository;
use App\modules\booking\services\HospitalityOrderAmount;
use App\modules\booking\services\HospitalityOrderDiff;
use App\modules\booking\services\HospitalityOrderGuard;
use App\modules\bookingfrontend\controllers\applications\HospitalityOrderController as CitizenOrderController;
use App\modules\bookingfrontend\helpers\ApplicationHelper;
use App\modules\bookingfrontend\helpers\UserHelper;
use App\modules\bookingfrontend\services\applications\ApplicationService;
use App\modules\bookingfrontend\services\applications\CateringChangeNotifier;
use Psr\Http\Message\ServerRequestInterface;

// The global lang() the controller reaches for when it refuses a request; the text is irrelevant here.
if (!function_exists('lang')) {
    function lang($key, ...$args)
    {
        return "!{$key}";
    }
}

// --- assertion kit (same shape as run_citizen.php) -----------------------------------------------------
$failures = [];
$passed = 0;
// KP_VERBOSE=1 prints every row, PASS or FAIL, so a base run can be listed row by row.
$verbose = getenv('KP_VERBOSE') !== false;
$check = static function (string $name, bool $ok, string $detail = '') use (&$failures, &$passed, $verbose): void {
    if ($verbose) {
        echo ($ok ? 'ROW PASS ' : 'ROW FAIL ') . $name . "\n";
    }
    if ($ok) {
        $passed++;
        return;
    }
    $failures[] = $name . ($detail !== '' ? " — {$detail}" : '');
};
$same = static function (string $name, mixed $got, mixed $want) use ($check): void {
    $check($name, $got === $want, 'got ' . var_export($got, true) . ' want ' . var_export($want, true));
};

// =======================================================================================================
// The stubs. The database is replaced; the controller, resolveEffectivePricing, the amount guard and the
// diff are the code under test.
// =======================================================================================================

/** fm_ecomva as the fixture describes it: 0 = 0 %, 1 = 25 %, 2 = 15 %. */
final class LockArticleRepo extends HospitalityArticleRepository
{
    /** @var array<int, array> the article rows getArticleById() returns: the menu as the tables hold it today */
    public static array $menu = [];
    /** @var array<int, int> fm_ecomva id => whole percent */
    public static array $rates = [0 => 0, 1 => 25, 2 => 15];

    public function __construct()
    {
    }

    public function getArticleById(int $id): ?array
    {
        return self::$menu[$id] ?? null;
    }

    public function getTaxPercentByCode(): array
    {
        return self::$rates;
    }
}

final class LockOrderRepo extends HospitalityOrderRepository
{
    /** @var array<int, array> */
    public array $orders = [];
    /** @var array<int, list<array>> */
    public array $lines = [];
    /** @var list<string> */
    public array $calls = [];
    /** @var list<array> */
    public array $entries = [];
    public bool $wrote = false;
    private int $nextLineId = 900;

    public function __construct()
    {
    }

    public function transactional(callable $work): mixed
    {
        return $work();
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

    public function getEarliestApplicationDate(int $applicationId): ?string
    {
        return gmdate('Y-m-d H:i:s', strtotime('+30 days'));
    }

    public function isPaidAtCheckout(int $applicationId, int $hospitalityId): bool
    {
        return false;
    }

    public function update(int $id, array $data): bool
    {
        $this->orders[$id] = array_replace($this->orders[$id], $data);
        $this->wrote = true;
        $this->calls[] = 'update';
        return true;
    }

    /** As the database stores it: the amount the caller judged when it sent one, else quantity × unit price. */
    public function addOrderLine(array $data): int
    {
        $this->calls[] = 'addOrderLine';
        $orderId = (int)$data['order_id'];
        $tax = (int)$data['tax_code'];
        $id = $this->nextLineId++;
        $this->lines[$orderId][] = [
            'id' => $id,
            'order_id' => $orderId,
            'hospitality_article_id' => (int)$data['hospitality_article_id'],
            'quantity' => number_format((float)($data['quantity'] ?? 1), 2, '.', ''),
            'unit_price' => number_format((float)$data['unit_price'], 2, '.', ''),
            'tax_code' => $tax,
            'tax_percent' => LockArticleRepo::$rates[$tax] ?? null,
            'amount' => $data['amount'] ?? number_format((float)($data['quantity'] ?? 1) * (float)$data['unit_price'], 2, '.', ''),
            'comment' => $data['comment'] ?? null,
            'article_name' => LockArticleRepo::$menu[(int)$data['hospitality_article_id']]['article_name'] ?? '?',
            'unit' => 'stk',
        ];
        $this->wrote = true;
        return $id;
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
}

final class LockApplicationService extends ApplicationService
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

final class LockApplicationHelper extends ApplicationHelper
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

final class LockUserHelper extends UserHelper
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

final class LockHospitalityRepo extends HospitalityRepository
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

final class LockNotifier extends CateringChangeNotifier
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

// =======================================================================================================
// Fixtures. Article 11 Kaffe, 12 Kake, 13 Vann (0 %), 14 Brød (new in the rows), 15 Te (overridden),
// 16 Ølfri (deactivated), 17 Nopris (its price row is gone in one row).
// =======================================================================================================
$menuRow = static fn(int $id, string $name, ?string $base, int $baseTax, ?string $override = null, ?int $overrideTax = null, int $active = 1): array => [
    'id' => $id, 'hospitality_id' => 3, 'active' => $active, 'article_name' => $name, 'unit' => 'stk',
    'base_price' => $base, 'base_tax_code' => $baseTax, 'override_price' => $override, 'override_tax_code' => $overrideTax,
];
$menuDefault = [
    11 => $menuRow(11, 'Kaffe', '45.00', 1),
    12 => $menuRow(12, 'Kake', '100.00', 1),
    13 => $menuRow(13, 'Vann', '20.00', 0),
    14 => $menuRow(14, 'Brød', '60.00', 2),
    15 => $menuRow(15, 'Te', '80.00', 1, '77.70', 2),
    16 => $menuRow(16, 'Ølfri', '30.00', 1, null, null, 0),
    17 => $menuRow(17, 'Nopris', '50.00', 1),
];

// The order as it was placed: [stored line id, article, quantity, unit price (ex VAT), tax code, amount].
$storedDefault = [
    [401, 11, '3.00', '45.00', 1, '135.00'],
    [402, 12, '2.00', '100.00', 1, '200.00'],
    [403, 13, '1.00', '20.00', 0, '20.00'],
    [404, 15, '1.00', '77.70', 2, '77.70'],
    [405, 16, '2.00', '30.00', 1, '60.00'],
    [406, 17, '1.00', '50.00', 1, '50.00'],
];
// The same six lines as [article, quantity, unit price, tax code, amount], the shape the assertions compare.
$expectedDefault = array_map(static fn(array $t): array => [$t[1], $t[2], $t[3], $t[4], $t[5]], $storedDefault);

/** A request line list: [article, quantity] pairs in the order the client sends them. */
$req = static fn(array $pairs): string => json_encode(array_map(
    static fn(array $p): array => ['hospitality_article_id' => $p[0], 'quantity' => $p[1]],
    $pairs
));
// Every line of the order, unchanged.
$untouched = [[11, 3], [12, 2], [13, 1], [15, 1], [16, 2], [17, 1]];

$servingUtc = gmdate('Y-m-d H:i:s', strtotime('+30 days'));
$application = [
    'id' => 84091, 'status' => 'ACCEPTED', 'id_string' => '84091', 'name' => 'Sommerfest',
    'building_id' => 1, 'building_name' => 'Bygg A', 'customer_organization_name' => null,
    'case_officer_id' => 5, 'activity_id' => null,
];
$orderRow = [
    'id' => 7, 'application_id' => 84091, 'hospitality_id' => 3, 'location_resource_id' => 5, 'status' => 'pending',
    'comment' => 'Uten nøtter', 'special_requirements' => null, 'serving_time_iso' => $servingUtc,
    'created' => '2026-10-20 10:00:00', 'modified' => '2026-10-21 11:00:00', 'created_by' => 4242, 'modified_by' => 4243,
    'billed' => 0, 'hospitality_name' => 'Kjøkken', 'location_name' => 'Sal A',
];

$citizenRef = new \ReflectionClass(CitizenOrderController::class);
$makeCitizen = static function (LockOrderRepo $repo, LockNotifier $notifier, array $app) use ($citizenRef): object {
    $service = new LockApplicationService();
    $service->application = $app;
    $user = new LockUserHelper();
    $user->ssn = '01010112345';
    $c = $citizenRef->newInstanceWithoutConstructor();
    foreach ([
        'applicationHelper' => new LockApplicationHelper(),
        'applicationService' => $service,
        'userHelper' => $user,
        'hospitalityRepo' => new LockHospitalityRepo(),
        'articleRepo' => new LockArticleRepo(),
        'orderRepo' => $repo,
        'guard' => new HospitalityOrderGuard(),
        'notifier' => $notifier,
    ] as $prop => $value) {
        $citizenRef->getProperty($prop)->setValue($c, $value);
    }
    return $c;
};

/**
 * One PUT of order 7 against the given menu and stored lines.
 *
 * @param array<int, array> $menu
 * @param list<array{0: int, 1: int, 2: string, 3: string, 4: int, 5: string}> $stored [id, article, quantity, unit, tax, amount]
 * @return array{status: ?int, body: string, threw: ?\Throwable, repo: LockOrderRepo, notifier: LockNotifier, before: ?array}
 */
$run = static function (array $menu, array $stored, string $linesJson) use ($orderRow, $application, $makeCitizen): array {
    LockArticleRepo::$menu = $menu;
    $repo = new LockOrderRepo();
    $repo->orders[7] = $orderRow;
    $repo->lines[7] = array_map(static fn(array $t): array => [
        'id' => $t[0], 'order_id' => 7, 'hospitality_article_id' => $t[1], 'quantity' => $t[2], 'unit_price' => $t[3],
        'tax_code' => $t[4], 'tax_percent' => LockArticleRepo::$rates[$t[4]], 'amount' => $t[5], 'comment' => null,
        'article_name' => $menu[$t[1]]['article_name'], 'unit' => 'stk',
    ], $stored);
    $notifier = new LockNotifier();
    $before = $repo->getOrderWithLines(7);
    $controller = $makeCitizen($repo, $notifier, $application);
    try {
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('PUT', 'https://pe-api.test/bookingfrontend/x');
        $request = $request->withBody((new \Slim\Psr7\Factory\StreamFactory())->createStream('{"lines":' . $linesJson . '}'));
        $response = $controller->updateOrder($request->withHeader('Content-Type', 'application/json'), new \Slim\Psr7\Response(), ['id' => 84091, 'orderId' => 7]);
        $out = ['status' => $response->getStatusCode(), 'body' => (string)$response->getBody(), 'threw' => null];
    } catch (\Throwable $e) {
        $out = ['status' => null, 'body' => '', 'threw' => $e];
    }
    return $out + ['repo' => $repo, 'notifier' => $notifier, 'before' => $before];
};

/** [article, quantity, unit price, tax code, amount] of every stored line, in stored order. */
$linesOf = static fn(LockOrderRepo $repo): array => array_map(
    static fn(array $l): array => [(int)$l['hospitality_article_id'], $l['quantity'], $l['unit_price'], (int)$l['tax_code'], $l['amount']],
    $repo->lines[7] ?? []
);
/** The stored lines of one article, in stored order. */
$lineOf = static fn(LockOrderRepo $repo, int $article): array => array_values(array_filter(
    $linesOf($repo),
    static fn(array $l): bool => $l[0] === $article
));

// =======================================================================================================
// 1. An untouched line after a menu PRICE change keeps its price.
// =======================================================================================================
$menu = $menuDefault;
$menu[11] = $menuRow(11, 'Kaffe', '60.00', 1);
$r = $run($menu, $storedDefault, $req($untouched));
$same('1. untouched order after Kaffe goes 45 → 60: 200', $r['status'], 200);
$same('1. untouched order after a menu PRICE change: every line keeps the price, VAT and amount it was ordered at', $linesOf($r['repo']), $expectedDefault);

// =======================================================================================================
// 2. An untouched line after a VAT change keeps its VAT.
// =======================================================================================================
$menu = $menuDefault;
$menu[13] = $menuRow(13, 'Vann', '20.00', 1);
$r = $run($menu, $storedDefault, $req($untouched));
$same('2. untouched order after Vann goes 0 % → 25 %: 200', $r['status'], 200);
$same('2. untouched order after a menu VAT change: the line keeps its tax code 0 and 0 % (amount 20.00)', $lineOf($r['repo'], 13), [[13, '1.00', '20.00', 0, '20.00']]);

// =======================================================================================================
// 3. A quantity change keeps the ordered unit price: old unit price × new quantity.
// =======================================================================================================
$menu = $menuDefault;
$menu[12] = $menuRow(12, 'Kake', '130.00', 1);
$r = $run($menu, $storedDefault, $req([[11, 3], [12, 3], [13, 1], [15, 1], [16, 2], [17, 1]]));
$same('3. Kake 2 → 3 with the menu at 130: 200', $r['status'], 200);
$same('3. the changed line keeps 100.00 and VAT 1, the amount is 3 × 100.00 = 300.00', $lineOf($r['repo'], 12), [[12, '3.00', '100.00', 1, '300.00']]);

// =======================================================================================================
// 4. A NEW line takes today's menu price and VAT.
// =======================================================================================================
$r = $run($menuDefault, $storedDefault, $req([...$untouched, [14, 2]]));
$same('4. a new Brød line: 200', $r['status'], 200);
$same('4. the new line takes the menu price 60.00 and its VAT code 2, amount 2 × 60.00', $lineOf($r['repo'], 14), [[14, '2.00', '60.00', 2, '120.00']]);

// =======================================================================================================
// 5. A removed line is removed; the others are kept.
// =======================================================================================================
$r = $run($menuDefault, $storedDefault, $req([[11, 3], [12, 2], [13, 1], [15, 1], [17, 1]]));
$same('5. a line left out of the request: 200', $r['status'], 200);
$same('5. the removed line (Ølfri) is gone; the other lines are kept as ordered',
    $linesOf($r['repo']),
    array_values(array_filter($expectedDefault, static fn(array $l): bool => $l[0] !== 16))
);

// =======================================================================================================
// 6. Mixed VAT 0 / 15 / 25 with an overridden article: the override it was ordered at is kept.
// =======================================================================================================
$menu = $menuDefault;
$menu[15] = $menuRow(15, 'Te', '80.00', 1);
$r = $run($menu, $storedDefault, $req($untouched));
$same('6. Te: the override (77.70 at 15 %) is gone from the menu: 200', $r['status'], 200);
$same('6. the ordered Te line keeps 77.70 at VAT code 2 (15 %), not the base 80.00 at 25 %', $lineOf($r['repo'], 15), [[15, '1.00', '77.70', 2, '77.70']]);
$same('6. mixed VAT: 0 % Vann, 15 % Te and 25 % Kaffe lines all keep their stored codes',
    array_map(static fn(int $a): int => (int)$lineOf($r['repo'], $a)[0][3], [13, 15, 11]),
    [0, 2, 1]
);

// =======================================================================================================
// 7. An untouched line whose article was DEACTIVATED is kept as ordered (no refusal, no re-price).
// =======================================================================================================
$menu = $menuDefault;
$menu[16] = $menuRow(16, 'Ølfri', '35.00', 1, null, null, 0);
$r = $run($menu, $storedDefault, $req($untouched));
$same('7. untouched inactive article (Ølfri 30 → 35 on the menu): 200', $r['status'], 200);
$same('7. the deactivated, untouched line is kept at 30.00 with its VAT and amount 60.00', $lineOf($r['repo'], 16), [[16, '2.00', '30.00', 1, '60.00']]);

// =======================================================================================================
// 8. An untouched line whose PRICE ROW was removed is kept, not re-priced at 0.00 with 0 % VAT.
// =======================================================================================================
$menu = $menuDefault;
$menu[17] = $menuRow(17, 'Nopris', null, 1);
$r = $run($menu, $storedDefault, $req($untouched));
$same('8. untouched line whose price row is gone: 200', $r['status'], 200);
$same('8. that line keeps 50.00 and VAT code 1, not 0.00 and 0 %', $lineOf($r['repo'], 17), [[17, '1.00', '50.00', 1, '50.00']]);

// =======================================================================================================
// 9. The amount guard judges the STORED price: 99,999,999 × 45.00 is refused, though the menu says 0.01.
// =======================================================================================================
$menu = $menuDefault;
$menu[11] = $menuRow(11, 'Kaffe', '0.01', 1);
$r = $run($menu, $storedDefault, $req([[11, 99999999], [12, 2], [13, 1], [15, 1], [16, 2], [17, 1]]));
$same('9. 99,999,999 Kaffe at the stored 45.00: 422 (the amount does not fit), not 200', $r['status'], 422);
$same('9. nothing is written and the lines are as they were', [$r['repo']->wrote, $linesOf($r['repo'])], [false, $expectedDefault]);

// =======================================================================================================
// 10. A fractional quantity is refused before anything is written; the øre arithmetic of 2,5 × 106,40.
// =======================================================================================================
$r = $run($menuDefault, $storedDefault, $req([[11, 2.5], [12, 2], [13, 1], [15, 1], [16, 2], [17, 1]]));
$same('10. Kaffe quantity 2,5: 400 (whole numbers only, ruling 206)', $r['status'], 400);
$same('10. nothing is written', [$r['repo']->wrote, $linesOf($r['repo'])], [false, $expectedDefault]);
$same('10. 2,5 × 106,40 is 266,00 kr in whole øre (26600), the tax 25 % of it 6650',
    [HospitalityOrderAmount::line('2.5', '106.40', 1, [0 => 0, 1 => 25])['net'], HospitalityOrderAmount::line('2.5', '106.40', 1, [0 => 0, 1 => 25])['tax']],
    [26600, 6650]
);
// Guarded: a tree without numeric() answers with a failed row, not with a fatal error.
$numeric = static fn(int $ore): ?string => is_callable([HospitalityOrderAmount::class, 'numeric']) ? HospitalityOrderAmount::numeric($ore) : null;
$same('10. øre text: 26600 → "266.00", 1234 → "12.34", 100 → "1.00", 0 → "0.00", -5 → "-0.05"',
    [$numeric(26600), $numeric(1234), $numeric(100), $numeric(0), $numeric(-5)],
    ['266.00', '12.34', '1.00', '0.00', '-0.05']
);

// =======================================================================================================
// 11. Two stored lines of ONE article (the table has no unique key on order and article): each request line for
//     it takes the next stored line in stored order; a third one is NEW and takes today's price.
// =======================================================================================================
$storedTwin = [...$storedDefault, [407, 11, '1.00', '50.00', 1, '50.00']];
$menu = $menuDefault;
$menu[11] = $menuRow(11, 'Kaffe', '60.00', 1);
$r = $run($menu, $storedTwin, $req([[11, 3], [11, 1], [12, 2], [13, 1], [15, 1], [16, 2], [17, 1]]));
$same('11. two Kaffe lines in the request: 200', $r['status'], 200);
$same('11. the first Kaffe line takes the first stored price (45.00), the second the second stored price (50.00)',
    $lineOf($r['repo'], 11),
    [[11, '3.00', '45.00', 1, '135.00'], [11, '1.00', '50.00', 1, '50.00']]
);
$r = $run($menu, $storedTwin, $req([[11, 3], [11, 1], [11, 1], [12, 2], [13, 1], [15, 1], [16, 2], [17, 1]]));
$same('11b. a third Kaffe line (no stored one left) is NEW: today\'s 60.00',
    $lineOf($r['repo'], 11),
    [[11, '3.00', '45.00', 1, '135.00'], [11, '1.00', '50.00', 1, '50.00'], [11, '1.00', '60.00', 1, '60.00']]
);

// =======================================================================================================
// 12. The change log: an untouched line produces no change row; the changed one does.
// =======================================================================================================
$menu = $menuDefault;
$menu[11] = $menuRow(11, 'Kaffe', '60.00', 1);
$r = $run($menu, $storedDefault, $req([[11, 3], [12, 3], [13, 1], [15, 1], [16, 2], [17, 1]]));
$after = $r['repo']->getOrderWithLines(7);
$diff = HospitalityOrderDiff::between($r['before'], $after);
$same('12. a change to Kake only, with Kaffe re-priced on the menu: the change log names Kake and the total, not Kaffe',
    array_column($diff['rows'], 'key'),
    ['line:Kake', 'total']
);

// =======================================================================================================
// 16. A line whose amount cannot be judged is refused BEFORE any write: a float fallback would write it. Three cases:
//     a kept line whose stored price is not a number, a new line whose menu price is NaN, and a kept line whose amount
//     does not fit the column (the guard's own judgement, reached with a stored price).
$storedBad = $storedDefault;
$storedBad[0] = [401, 11, '3.00', 'abc', 1, '135.00'];
$r = $run($menuDefault, $storedBad, $req($untouched));
$same('16a. a kept line whose stored price is not a number: 400, not written as a float', $r['status'], 400);
$same('16a. nothing is written and the lines are as they were',
    [$r['repo']->wrote, $linesOf($r['repo'])],
    [false, array_map(static fn(array $t): array => [$t[1], $t[2], $t[3], $t[4], $t[5]], $storedBad)]
);

$menuNaN = $menuDefault;
$menuNaN[14] = array_replace($menuRow(14, 'Brød', '60.00', 2), ['base_price' => NAN]);
$r = $run($menuNaN, $storedDefault, $req([...$untouched, [14, 2]]));
$same('16b. a new Brød line whose menu price is NaN: 400', $r['status'], 400);
$same('16b. nothing is written', [$r['repo']->wrote, $linesOf($r['repo'])], [false, $expectedDefault]);

$storedHuge = $storedDefault;
$storedHuge[0] = [401, 11, '3.00', '99999999.99', 1, '135.00'];
$r = $run($menuDefault, $storedHuge, $req([[11, 99999999], [12, 2], [13, 1], [15, 1], [16, 2], [17, 1]]));
$same('16c. a kept line that does not fit (99 999 999 × 99 999 999,99): 422', $r['status'], 422);
$same('16c. nothing is written',
    [$r['repo']->wrote, $linesOf($r['repo'])],
    [false, array_map(static fn(array $t): array => [$t[1], $t[2], $t[3], $t[4], $t[5]], $storedHuge)]
);

// =======================================================================================================
// Result
// =======================================================================================================
$total = $passed + count($failures);
if ($failures !== []) {
    foreach ($failures as $f) {
        echo "FAIL {$f}\n";
    }
    echo "Catering keep-price harness: FAIL {$passed} of {$total} checks passed on PHP " . PHP_VERSION . "\n";
    exit(1);
}
echo "Catering keep-price harness: PASS {$passed} checks on PHP " . PHP_VERSION . "\n";
exit(0);
