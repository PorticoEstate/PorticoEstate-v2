<?php

/**
 * The catering write paths against a REAL PostgreSQL (§49596): one transaction per applicant create/update (lane 10),
 * nothing told before the commit (lane 13), the real numeric(10,2) columns (lanes 1, 2, 8, 11), and billing's own
 * arithmetic through numeric(10,2) next to the guard's (lane 14).
 *
 *   HD_PG_DSN='pgsql:host=127.0.0.1;port=5435;dbname=postgres' HD_PG_USER=postgres HD_PG_PASS=... \
 *       php tests/hospitality-amount/run_pg.php
 *
 * Why a database of its own: the transaction is opened only when none is open ($own = !inTransaction()), so a test
 * that wrapped the run in an outer transaction to undo it would make $own false and test nothing. This creates a
 * SCRATCH DATABASE (hd_amount_<pid>_<6 hex>), creates in it the three order tables with the test database's own DDL
 * (numeric(10,2), the sequences, the foreign keys) and stand-ins for the tables they join, runs the REAL bookingfrontend
 * controller and the REAL HospitalityOrderRepository on it, and drops it at the end. No table of the application is
 * read or written. It refuses to create anything unless it is connected to the database it just created and that
 * database is empty. The DSN user needs CREATEDB; without it, or without HD_PG_DSN, it prints SKIPPED and exits 3.
 *
 * The state a row compares is read through the SAME connection the controller wrote on (so a transaction left open
 * would show its rows) and through a SECOND one (which sees only what was committed).
 *
 * Environment: HD_PG_DSN, HD_PG_USER, HD_PG_PASS (required); HD_ROOT = tree holding src/ (default: this repo);
 * VENDOR_AUTOLOAD (default: this repo's). Exit code: 0 all pass, 1 any failure, 3 skipped.
 */

date_default_timezone_set('Europe/Oslo');
error_reporting(E_ALL & ~E_DEPRECATED);

use App\Database\Db;

$root = getenv('HD_ROOT') ?: dirname(__DIR__, 2);
require_once getenv('VENDOR_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';
// The stubs' parents reach the legacy includes through these two constants; they point at THIS repo, never at HD_ROOT.
define('PHPGW_SERVER_ROOT', dirname(__DIR__, 2) . '/src/modules');
define('SRC_ROOT_PATH', dirname(__DIR__, 2) . '/src');

$dsn = getenv('HD_PG_DSN');
$user = getenv('HD_PG_USER');
$pass = getenv('HD_PG_PASS');
if (!$dsn || $user === false) {
    echo "Amount PG harness: SKIPPED (set HD_PG_DSN, HD_PG_USER, HD_PG_PASS: it needs a real Postgres with CREATEDB)\n";
    exit(3);
}

const SCRATCH = '/^hd_amount_[0-9]+_[0-9a-f]{6}$/D';
$scratch = 'hd_amount_' . getmypid() . '_' . bin2hex(random_bytes(3));
try {
    $admin = new PDO($dsn, $user, (string)$pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $admin->exec("CREATE DATABASE \"{$scratch}\" TEMPLATE template0");
} catch (Throwable $e) {
    echo 'Amount PG harness: SKIPPED (cannot create a scratch database - the user needs CREATEDB: ' . $e->getMessage() . ")\n";
    exit(3);
}
$dsnFor = static fn(string $db): string => preg_match('/dbname=[^;]*/', $dsn) ? preg_replace('/dbname=[^;]*/', 'dbname=' . $db, $dsn) : $dsn . ';dbname=' . $db;

$exit = 1;
try {
    // The application's connection, opened BEFORE anything can ask Db::getInstance() for one without a DSN (Settings
    // does, when the stubs' parents load) and get the SQLite stand-in instead.
    $db = Db::getInstance($dsnFor($scratch), $user, (string)$pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $peer = new PDO($dsnFor($scratch), $user, (string)$pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $exit = hd_run($root, $db, $peer, $scratch);
} catch (Throwable $e) {
    echo 'Amount PG harness: ERROR ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() . "\n";
    $exit = 1;
} finally {
    $peer = null;
    $admin->exec("DROP DATABASE IF EXISTS \"{$scratch}\" WITH (FORCE)");
}
exit($exit);

// ======================================================================================================================
function hd_run(string $root, Db $db, PDO $peer, string $scratch): int
{
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

    // --- the guard: this connection is the scratch database, and it is empty -------------------------------------------
    $connectedTo = (string)hd_select($db, 'SELECT current_database()')->fetchColumn();
    $tables = (int)hd_select($db, "SELECT count(*) FROM pg_tables WHERE schemaname = 'public'")->fetchColumn();
    if ($connectedTo !== $scratch || preg_match(SCRATCH, $connectedTo) !== 1 || $tables !== 0) {
        echo "Amount PG harness: REFUSED (connected to «{$connectedTo}» holding {$tables} tables; expected the empty «{$scratch}»)\n";
        return 2;
    }
    $check('guard: connected to the scratch database just created, and it was empty', true);

    // --- schema: the order tables as the test database defines them (pg_dump --schema-only, 2026-11-24) -----------------
    foreach ([
        'CREATE TABLE bb_application (id integer PRIMARY KEY)',
        'CREATE TABLE bb_hospitality (id integer PRIMARY KEY, name text)',
        'CREATE TABLE bb_resource (id integer PRIMARY KEY, name text)',
        'CREATE TABLE bb_hospitality_article (id integer PRIMARY KEY, article_mapping_id integer)',
        'CREATE TABLE bb_article_mapping (id integer PRIMARY KEY, article_id integer, article_cat_id integer, unit text)',
        'CREATE TABLE bb_article_view (id integer, article_cat_id integer, name text)',
        'CREATE TABLE fm_ecomva (id integer PRIMARY KEY, descr text, percent_ integer)',
        'CREATE TABLE phpgw_accounts (account_id integer, account_lid text)',
        'CREATE TABLE bb_user (id integer, name text)',
        'CREATE SEQUENCE seq_bb_hospitality_order',
        'CREATE SEQUENCE seq_bb_hospitality_order_line',
        "CREATE TABLE bb_hospitality_order (
            id integer DEFAULT nextval('seq_bb_hospitality_order'::regclass) NOT NULL PRIMARY KEY,
            application_id integer NOT NULL REFERENCES bb_application(id),
            hospitality_id integer NOT NULL REFERENCES bb_hospitality(id),
            location_resource_id integer NOT NULL REFERENCES bb_resource(id),
            status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
            comment text, special_requirements text,
            created timestamp without time zone DEFAULT (now() AT TIME ZONE 'UTC'::text) NOT NULL,
            modified timestamp without time zone DEFAULT (now() AT TIME ZONE 'UTC'::text) NOT NULL,
            created_by integer, modified_by integer, serving_time_iso timestamp without time zone,
            billed integer DEFAULT 0 NOT NULL)",
        "CREATE TABLE bb_hospitality_order_line (
            id integer DEFAULT nextval('seq_bb_hospitality_order_line'::regclass) NOT NULL PRIMARY KEY,
            order_id integer NOT NULL REFERENCES bb_hospitality_order(id),
            hospitality_article_id integer NOT NULL REFERENCES bb_hospitality_article(id),
            quantity numeric(10,2) DEFAULT 1.0 NOT NULL, unit_price numeric(10,2) NOT NULL, tax_code integer NOT NULL,
            amount numeric(10,2) NOT NULL, comment text)",
        "CREATE TABLE bb_hospitality_order_changelog (
            id serial PRIMARY KEY, order_id integer NOT NULL REFERENCES bb_hospitality_order(id),
            case_officer_id integer, booking_user_id integer,
            changed_at timestamp without time zone DEFAULT (now() AT TIME ZONE 'UTC'::text) NOT NULL,
            change_type character varying(50) NOT NULL, old_value jsonb, new_value jsonb, comment text NOT NULL,
            CONSTRAINT chk_changelog_user CHECK (((case_officer_id IS NOT NULL) OR (booking_user_id IS NOT NULL))))",
        "INSERT INTO bb_application (id) VALUES (85300), (85301)",
        "INSERT INTO bb_hospitality (id, name) VALUES (4, 'Kjøkken')",
        "INSERT INTO bb_resource (id, name) VALUES (7, 'Sal A')",
        "INSERT INTO bb_hospitality_article (id, article_mapping_id) VALUES (5, 859), (9, 865), (11, 867), (19, 869)",
        "INSERT INTO bb_article_mapping (id, article_id, article_cat_id, unit) VALUES (859, 1, 2, 'stk'), (865, 2, 2, 'stk'), (867, 3, 2, 'stk'), (869, 4, 2, 'stk')",
        // fm_ecomva as the test database has it, plus the lane-5 fixture's 15 % code
        "INSERT INTO fm_ecomva (id, descr, percent_) VALUES (0, 'ingen', 0), (9, 'mva', 25), (12, 'mva', 25), (15, 'fixture', 15), (60, 'mva', 25), (75, 'mva', 25)",
    ] as $sql) {
        $db->exec($sql);
    }

    foreach ([
        'traits/SerializableTrait.php', 'helpers/ResponseHelper.php',
        'modules/booking/models/HospitalityOrder.php', 'modules/booking/models/HospitalityOrderLine.php',
        'modules/booking/services/HospitalityDeadlineCalculator.php', 'modules/booking/services/HospitalityOrderGuard.php',
        'modules/booking/services/HospitalityOrderDiff.php', 'modules/booking/services/HospitalityOrderQuantity.php',
        'modules/booking/services/CateringText.php', 'modules/booking/services/CateringLangSwitch.php',
        'modules/booking/services/CateringMailRenderer.php', 'modules/booking/services/CateringChangeText.php',
        'modules/booking/services/TranslationLangSwitch.php',
        'modules/booking/repositories/HospitalityOrderRepository.php', 'modules/booking/repositories/HospitalityArticleRepository.php',
        'modules/bookingfrontend/services/applications/CateringChangeNotifier.php',
        'modules/bookingfrontend/services/applications/CitizenHospitalityOrder.php',
        'modules/bookingfrontend/controllers/applications/HospitalityOrderController.php',
    ] as $file) {
        require_once $root . '/src/' . $file;
    }
    if (is_file($root . '/src/modules/booking/services/HospitalityOrderAmount.php')) {
        require_once $root . '/src/modules/booking/services/HospitalityOrderAmount.php';
    }
    if (!function_exists('lang')) {
        function lang($key, ...$args)
        {
            return "!{$key}";
        }
    }
    $logFile = tempnam(sys_get_temp_dir(), 'hd-amount-pg-log-');
    ini_set('error_log', $logFile);
    ini_set('log_errors', '1');

    // --- the probes ---------------------------------------------------------------------------------------------------
    /** The REAL repository, with a throw before the Nth addOrderLine and a record of the transaction state at each read-back and entry. */
    $probe = new class extends \App\modules\booking\repositories\HospitalityOrderRepository {
        /** @var (\Closure(int): ?\Throwable)|null */
        public ?\Closure $throwAt = null;
        public int $adds = 0;
        /** @var list<array{string, bool}> [what, inTransaction] at every getOrderWithLines and addChangelogEntry */
        public array $seen = [];

        public function addOrderLine(array $data): int
        {
            $this->adds++;
            if ($this->throwAt !== null && ($e = ($this->throwAt)($this->adds)) !== null) {
                throw $e;
            }
            return parent::addOrderLine($data);
        }

        public function getOrderWithLines(int $orderId): ?array
        {
            $this->seen[] = ['read', (bool)\App\Database\Db::getInstance()->inTransaction()];
            return parent::getOrderWithLines($orderId);
        }

        public function addChangelogEntry(array $data): int
        {
            $this->seen[] = ['entry:' . $data['change_type'], (bool)\App\Database\Db::getInstance()->inTransaction()];
            return parent::addChangelogEntry($data);
        }

        // Two reads this lane does not test, whose tables (bb_purchase_order, bb_payment, bb_application_date) the
        // scratch database does not hold. They write nothing.
        public function isPaidAtCheckout(int $applicationId, int $hospitalityId): bool
        {
            return false;
        }

        public function getEarliestApplicationDate(int $applicationId): ?string
        {
            return gmdate('Y-m-d H:i:s', strtotime('+30 days'));
        }
    };
    $notifier = new class extends \App\modules\bookingfrontend\services\applications\CateringChangeNotifier {
        /** @var list<bool> inTransaction at each notify() */
        public array $seen = [];

        public function __construct()
        {
        }

        public function notify(array $payload): bool
        {
            $this->seen[] = (bool)\App\Database\Db::getInstance()->inTransaction();
            return true;
        }
    };
    $articles = new class extends \App\modules\booking\repositories\HospitalityArticleRepository {
        // getTaxPercentByCode() is the REAL one, on the scratch fm_ecomva. The articles are those of hospitality 4 (measured 2026-11-24).
        public function getArticleById(int $id): ?array
        {
            $a = [
                5 => [null, null, '125.00', 9], 9 => [null, null, '356.00', 9], 11 => ['77.00', null, '65.00', 12], 19 => ['40.59', 0, '25.00', 12],
            ][$id] ?? null;
            return $a === null ? null : [
                'id' => $id, 'hospitality_id' => 4, 'active' => 1, 'override_price' => $a[0], 'override_tax_code' => $a[1],
                'base_price' => $a[2], 'base_tax_code' => $a[3], 'unit' => 'stk', 'article_name' => "Art {$id}",
            ];
        }
    };
    $hospitality = new class extends \App\modules\booking\repositories\HospitalityRepository {
        public function __construct()
        {
        }

        public function getById(int $id): ?array
        {
            return ['id' => $id, 'active' => 1, 'name' => 'Kjøkken', 'open_days' => 127, 'order_by_time_value' => null, 'order_by_time_unit' => null,
                'resource_cancellation_deadline_value' => null, 'resource_cancellation_deadline_unit' => null];
        }

        public function getDeliveryLocations(int $hospitalityId): array
        {
            return [['id' => 7, 'name' => 'Sal A']];
        }

        public function getContact(int $hospitalityId, int $fallbackBuildingId = 0): array
        {
            return ['contact_info' => null, 'building_name' => 'Bygg', 'building_email' => null, 'building_phone' => null];
        }
    };
    $appService = new class extends \App\modules\bookingfrontend\services\applications\ApplicationService {
        public array $application = [];

        public function __construct()
        {
        }

        public function getApplicationById(int $id): ?array
        {
            return (int)$this->application['id'] === $id ? $this->application : null;
        }
    };
    $appHelper = new class extends \App\modules\bookingfrontend\helpers\ApplicationHelper {
        public function __construct()
        {
        }

        public function canViewApplication(array $application, \Psr\Http\Message\ServerRequestInterface $request): bool
        {
            return true;
        }

        public function canModifyApplication(array $application, \Psr\Http\Message\ServerRequestInterface $request): bool
        {
            return true;
        }
    };
    $userHelper = new class extends \App\modules\bookingfrontend\helpers\UserHelper {
        public function __construct()
        {
        }

        public function get_user_id($ssn)
        {
            return 77;
        }
    };
    $userHelper->ssn = '01010112345';

    $ref = new \ReflectionClass(\App\modules\bookingfrontend\controllers\applications\HospitalityOrderController::class);
    $controller = $ref->newInstanceWithoutConstructor();
    foreach ([
        'applicationHelper' => $appHelper, 'applicationService' => $appService, 'userHelper' => $userHelper,
        'hospitalityRepo' => $hospitality, 'articleRepo' => $articles, 'orderRepo' => $probe,
        'guard' => new \App\modules\booking\services\HospitalityOrderGuard(), 'notifier' => $notifier,
    ] as $prop => $value) {
        $ref->getProperty($prop)->setValue($controller, $value);
    }

    $submitted = ['id' => 85300, 'status' => 'ACCEPTED', 'id_string' => '85300', 'name' => 'Jubileum', 'building_id' => 10,
        'building_name' => 'Bygg', 'customer_organization_name' => null, 'case_officer_id' => 17, 'activity_id' => null];
    $draft = ['status' => \App\modules\booking\services\HospitalityOrderGuard::DRAFT_STATUS, 'id' => 85301, 'id_string' => '85301'] + $submitted;

    /** @return array{status: ?int, json: mixed, threw: ?string} */
    $call = static function (string $handler, string $verb, array $args, array $body, array $app) use ($controller, $appService, $probe, $notifier): array {
        $appService->application = $app;
        $probe->adds = 0;
        $probe->seen = [];
        $notifier->seen = [];
        try {
            $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest($verb, 'https://pe-api.test/x')
                ->withBody((new \Slim\Psr7\Factory\StreamFactory())->createStream(json_encode($body)))->withHeader('Content-Type', 'application/json');
            $response = $controller->$handler($request, new \Slim\Psr7\Response(), $args);
            return ['status' => $response->getStatusCode(), 'json' => json_decode((string)$response->getBody(), true), 'threw' => null];
        } catch (\Throwable $e) {
            return ['status' => null, 'json' => null, 'threw' => get_class($e)];
        } finally {
            $probe->throwAt = null;
        }
    };
    $lines = static fn(array $l): array => array_map(static fn(array $x) => ['hospitality_article_id' => $x[0], 'quantity' => $x[1]], $l);
    $post = static fn(array $l, array $app) => $call('createOrder', 'POST', ['id' => $app['id']], [
        'hospitality_id' => 4, 'location_resource_id' => 7, 'serving_time_iso' => gmdate('c', strtotime('+30 days')), 'comment' => 'Ny', 'lines' => $lines($l),
    ], $app);
    $put = static fn(int $orderId, array $body, array $app) => $call('updateOrder', 'PUT', ['id' => $app['id'], 'orderId' => $orderId],
        isset($body['lines']) ? ['lines' => $lines($body['lines'])] + $body : $body, $app);

    /** Every order row and line, as JSON text, through the given connection: what «unchanged» is compared on. */
    $state = static function ($connection): string {
        $q = static fn(string $sql): string => (string)hd_select($connection, $sql)->fetchColumn();
        return $q("SELECT coalesce(json_agg(o ORDER BY o.id)::text, '[]') FROM bb_hospitality_order o")
            . $q("SELECT coalesce(json_agg(l ORDER BY l.id)::text, '[]') FROM bb_hospitality_order_line l")
            . $q("SELECT count(*) FROM bb_hospitality_order_changelog");
    };
    $count = static fn(string $table): int => (int)hd_select($db, "SELECT count(*) FROM {$table}")->fetchColumn();
    $inTx = static fn(): bool => (bool)$db->inTransaction();
    // A transaction the code under test left open has already failed its row; close it so the rows after it still run
    // (as the end of a PHP request would: the connection closes and PostgreSQL rolls it back).
    $closeLeak = static function () use ($db): void {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    };
    $lineRows = static fn(int $orderId): array => hd_select($db, "SELECT hospitality_article_id, quantity, unit_price, tax_code, amount FROM bb_hospitality_order_line WHERE order_id = {$orderId} ORDER BY id")->fetchAll(PDO::FETCH_NUM);
    /** Seed an order on a submitted application the way the test database holds one: committed, 4 lines. */
    $seed = static function (string $comment = 'Før') use ($db): int {
        $id = (int)hd_select($db, "INSERT INTO bb_hospitality_order (application_id, hospitality_id, location_resource_id, comment, created_by, modified_by, modified, serving_time_iso)
            VALUES (85300, 4, 7, " . $db->quote($comment) . ", 77, 77, '2026-11-20 10:00:00', (now() AT TIME ZONE 'UTC') + interval '30 days') RETURNING id")->fetchColumn();
        $db->exec("INSERT INTO bb_hospitality_order_line (order_id, hospitality_article_id, quantity, unit_price, tax_code, amount) VALUES
            ({$id}, 5, 3, 125.00, 9, 375.00), ({$id}, 9, 2, 356.00, 9, 712.00), ({$id}, 11, 1, 77.00, 12, 77.00), ({$id}, 19, 4, 40.59, 0, 162.36)");
        return $id;
    };

    // =================================================================================================================
    // Lane 10: ONE transaction. A throw before the 2nd line - a PDOException (the handlers catch it: 500) and a
    // TypeError (they do not: it escapes) - leaves the database exactly as it was.
    // =================================================================================================================
    $throwables = [
        'a PDOException' => [static fn() => new \PDOException('SQLSTATE[08006] the 2nd line failed'), 500, null],
        'a TypeError' => [static fn() => new \TypeError('the 2nd line blew up with an Error'), null, 'TypeError'],
    ];
    foreach ($throwables as $label => [$make, $status, $threw]) {
        $before = $state($db);
        $probe->throwAt = static fn(int $n): ?\Throwable => $n === 2 ? $make() : null;
        $r = $post([[5, 1], [9, 1], [19, 1]], $submitted);
        $same("lane 10 create, {$label} at line 2: the answer", [$r['status'], $r['threw']], [$status, $threw]);
        $same("lane 10 create, {$label} at line 2: the transaction is closed afterwards", $inTx(), false);
        $same("lane 10 create, {$label} at line 2: no order and no line, seen on the SAME connection (base: the order and line 1)", $state($db), $before);
        $same("lane 10 create, {$label} at line 2: ...and on a second connection", $state($peer), $before);
        $same("lane 10 create, {$label} at line 2: nobody told", $notifier->seen, []);
        $closeLeak();

        $orderId = $seed();
        $before = $state($db);
        $probe->throwAt = static fn(int $n): ?\Throwable => $n === 2 ? $make() : null;
        $r = $put($orderId, ['comment' => 'Etter', 'lines' => [[5, 2], [9, 2], [19, 2]]], $submitted);
        $same("lane 10 update, {$label} at line 2: the answer", [$r['status'], $r['threw']], [$status, $threw]);
        $same("lane 10 update, {$label} at line 2: the transaction is closed afterwards", $inTx(), false);
        $same("lane 10 update, {$label} at line 2: the order row, modified included, and its 4 lines byte-identical (base: comment written, lines gone)", $state($db), $before);
        $same("lane 10 update, {$label} at line 2: ...and on a second connection", $state($peer), $before);
        $same("lane 10 update, {$label} at line 2: nobody told", $notifier->seen, []);
        $closeLeak();
    }

    // Positive controls: the same instrument sees a write when there is one.
    $orders = $count('bb_hospitality_order');
    $r = $post([[5, 1], [9, 1], [19, 1]], $submitted);
    $same('lane 10 control: the same create without a throw -> 201, one order more, its 3 lines committed (second connection)',
        [$r['status'], $count('bb_hospitality_order') - $orders, (int)hd_select($peer, 'SELECT count(*) FROM bb_hospitality_order_line WHERE order_id = ' . (int)($r['json']['id'] ?? 0))->fetchColumn()], [201, 1, 3]);

    // A caller's transaction is joined, not committed: the caller's rollback undoes the create.
    $before = $state($peer);
    $db->beginTransaction();
    $r = $post([[5, 1]], $submitted);
    $joined = [$r['status'], $inTx()];
    $db->rollBack();
    $same('lane 10: inside a caller\'s transaction the create joins it (201, still open: not committed by the handler)', $joined, [201, true]);
    $same('lane 10: ...and the caller\'s rollback leaves nothing', $state($db), $before);

    // =================================================================================================================
    // Lane 13: the read-back, the case entry and the officer's mail come AFTER the commit
    // =================================================================================================================
    $r = $post([[5, 2], [9, 1]], $submitted);
    $same('lane 13 create (submitted): 201, the officer told once', [$r['status'], count($notifier->seen)], [201, 1]);
    $same('lane 13 create: told with NO transaction open', $notifier->seen, [false]);
    $same('lane 13 create: the read-back and the «Endret av søker» entry ran with no transaction open', $probe->seen, [['read', false], ['entry:applicant_change', false]]);
    $r = $post([[5, 2]], $draft);
    $same('lane 13 create (draft): 201, the «created» entry with no transaction open, nobody told', [$r['status'], $probe->seen, $notifier->seen], [201, [['read', false], ['entry:created', false]], []]);
    $orderId = $seed();
    $r = $put($orderId, ['comment' => 'Etter', 'lines' => [[5, 4]]], $submitted);
    $same('lane 13 update (submitted): 200, told once with no transaction open', [$r['status'], $notifier->seen], [200, [false]]);
    $same('lane 13 update: the before-read, the read-back and the entry all ran with no transaction open', $probe->seen, [['read', false], ['read', false], ['entry:applicant_change', false]]);
    $same('lane 13 update: ...and the change is committed (second connection)', hd_select($peer, "SELECT comment FROM bb_hospitality_order WHERE id = {$orderId}")->fetchColumn(), 'Etter');

    // =================================================================================================================
    // Lanes 1, 2, 8 and 11 against the REAL numeric(10,2) columns
    // =================================================================================================================
    $before = $state($peer);
    $r = $post([[5, 800000]], $submitted);
    $same('lane 1 (real column): POST [5×800000] -> 422 amount_too_large line 0 (base: 500)', [$r['status'], $r['json']['code'] ?? null, $r['json']['line'] ?? null], [422, 'amount_too_large', 0]);
    $same('lane 1 (real column): ...no order row (base: an orphan)', $state($peer), $before);
    $r = $post([[9, 1], [5, 800000]], $submitted);
    $same('lane 1 (real column): POST [9×1, 5×800000] -> 422 line 1, and still no order row', [$r['status'], $r['json']['line'] ?? null, $state($peer) === $before], [422, 1, true]);
    $orderId = $seed();
    $before = $state($peer);
    $r = $put($orderId, ['comment' => 'Etter', 'lines' => [[9, 6], [5, 800000], [9, 7]]], $submitted);
    $same('lane 2 (real column): PUT [9×6, 5×800000, 9×7] -> 422 line 1 (base: 500)', [$r['status'], $r['json']['code'] ?? null, $r['json']['line'] ?? null], [422, 'amount_too_large', 1]);
    $same('lane 2 (real column): ...the order byte-identical (base: comment written, only 9×6 left)', $state($peer), $before);
    $r = $post([[5, 799999], [9, 280898]], $submitted);
    $same('lane 8 (real column): C6 -> 422 order_total_too_large (base: 201 and stored)', [$r['status'], $r['json']['code'] ?? null, $state($peer) === $before], [422, 'order_total_too_large', true]);
    $r = $post([[19, 2463661]], $submitted);
    $same('lane 3 (real column): 2,463,661 × 40.59 -> 201 and stored as 99,999,999.99', [$r['status'], $lineRows((int)($r['json']['id'] ?? 0))], [201, [[19, '2463661.00', '40.59', 0, '99999999.99']]]);
    foreach ([
        'small' => [[[5, 2]], [[5, '2.00', '125.00', 9, '250.00']]],
        'large, legitimate (10,000 × 356.00)' => [[[9, 10000]], [[9, '10000.00', '356.00', 9, '3560000.00']]],
        'mixed' => [[[19, 3], [11, 4], [5, 1]], [[19, '3.00', '40.59', 0, '121.77'], [11, '4.00', '77.00', 12, '308.00'], [5, '1.00', '125.00', 9, '125.00']]],
        'override' => [[[11, 10]], [[11, '10.00', '77.00', 12, '770.00']]],
    ] as $label => [$l, $want]) {
        $r = $post($l, $submitted);
        $same("lane 11 (real column): create {$label} -> 201 and exactly these rows", [$r['status'], $lineRows((int)($r['json']['id'] ?? 0))], [201, $want]);
        $orderId = $seed();
        $r = $put($orderId, ['comment' => 'Etter', 'lines' => $l], $submitted);
        $same("lane 11 (real column): PUT {$label} -> 200 and exactly these rows", [$r['status'], $lineRows($orderId)], [200, $want]);
    }
    $orderId = $seed();
    $r = $put($orderId, ['comment' => 'Bare kommentar'], $submitted);
    $same('lane 11 (real column): a comment-only PUT -> 200, the comment saved, the 4 lines untouched',
        [$r['status'], hd_select($peer, "SELECT comment FROM bb_hospitality_order WHERE id = {$orderId}")->fetchColumn(), count($lineRows($orderId))], [200, 'Bare kommentar', 4]);

    // =================================================================================================================
    // Lane 14: billing's own arithmetic (async_task_update_reservation_state + add_payment), floats included, bound
    // through PDO into numeric(10,2) exactly as the job binds it - next to the guard's integer øre.
    // =================================================================================================================
    $cls = '\\App\\modules\\booking\\services\\HospitalityOrderAmount';
    $haveClass = class_exists($cls, false);
    $check('lane 14: HospitalityOrderAmount is loaded from this tree', $haveClass);
    $percents = [];
    foreach (hd_select($db, 'SELECT id, percent_ FROM fm_ecomva')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $percents[(int)$row['id']] = (int)$row['percent_'];
    }
    $column = $db->prepare('SELECT CAST(:v AS numeric(10,2))');
    /** What billing stores for one line: [amount, tax] as numeric(10,2) strings, or the database's error. */
    $billLine = static function (string $q, string $p, int $code) use ($column, $percents): array {
        $amount = (float)$q * (float)$p;                    // $amount = (float)$line['quantity'] * (float)$line['unit_price'];
        $tax = $amount * ($percents[$code] ?? 0) / 100;     // $tax = $amount * $percent / 100;
        $out = [];
        foreach ([$amount, $tax] as $v) {
            $column->execute([':v' => $v]);
            $out[] = (string)$column->fetchColumn();
        }
        return [$out, $amount, $tax];
    };
    $toOre = static fn(string $numeric): int => (int)round((float)$numeric * 100);
    foreach ([
        'the C4 line (2,463,661 × 40.59, 0 %)' => ['2463661', '40.59', 0],
        'lane 4 edge (639,999 × 125.00, 25 %)' => ['639999', '125.00', 9],
        'lane 5 edge (869,565 × 100.00, 15 %)' => ['869565', '100.00', 15],
        'half an øre of VAT (1 × 0.02, 25 %: 0.005)' => ['1', '0.02', 9],
        'half an øre (3 × 0.10, 25 %: 0.075)' => ['3', '0.10', 9],
        'half an øre (1 × 0.10, 15 %: 0.015)' => ['1', '0.10', 15],
        'half an øre (7 × 0.06, 25 %: 0.105)' => ['7', '0.06', 9],
        'a stored fractional quantity (2.50 × 0.03, 25 %)' => ['2.50', '0.03', 9],
        'an unknown code (99) is 0 %' => ['3', '100.00', 99],
    ] as $label => [$q, $p, $code]) {
        [[$amount, $tax]] = $billLine($q, $p, $code);
        $guard = $haveClass ? $cls::line($q, $p, $code, $percents) : null;
        $same("lane 14 {$label}: the guard's net and VAT are what billing stores", $guard, ['net' => $toOre($amount), 'tax' => $toOre($tax)]);
    }
    // The order's gross as add_payment writes it into bb_payment.amount: Σ (float)amount + (float)tax, through numeric(10,2).
    $billGross = static function (array $lines) use ($billLine, $column): ?string {
        $sum = 0.0;
        foreach ($lines as [$q, $p, $code]) {
            [[$amount, $tax]] = $billLine($q, $p, $code);
            $sum += (float)$amount + (float)$tax;
        }
        try {
            $column->execute([':v' => $sum]);
            return (string)$column->fetchColumn();
        } catch (\PDOException $e) {
            return null; // numeric field overflow: the payment cannot be written
        }
    };
    $asLines = static fn(array $lines): array => array_map(static fn(array $l) => ['quantity' => $l[0], 'unit_price' => $l[1], 'tax_code' => $l[2]], $lines);
    foreach ([
        'lane 4 edge' => [['639999', '125.00', 9]],
        'lane 7 mixed edge' => [['1000000', '40.59', 0], ['300000', '100.00', 15], ['200000', '77.00', 12], ['36223', '125.00', 9]],
        'a basket of half-øre lines' => [['1', '0.02', 9], ['3', '0.10', 9], ['1', '0.10', 15], ['7', '0.06', 9]],
    ] as $label => $l) {
        $paid = $billGross($l);
        $same("lane 14 {$label}: the guard's gross is bb_payment.amount as billing writes it", $haveClass ? $cls::gross($asLines($l), $percents) : null, $paid === null ? null : $toOre($paid));
        $same("lane 14 {$label}: ...and the guard lets it through", $haveClass ? $cls::refusal($asLines($l), $asLines($l), [], $percents) : 'no class', null);
    }
    foreach (['lane 4, one unit more' => [['640000', '125.00', 9]], 'lane 8, C6' => [['799999', '125.00', 9], ['280898', '356.00', 9]]] as $label => $l) {
        $same("lane 14 {$label}: bb_payment.amount cannot hold what billing would write", $billGross($l), null);
        $same("lane 14 {$label}: ...and the guard refuses it", $haveClass ? ($cls::refusal($asLines($l), $asLines($l), [], $percents)['code'] ?? null) : 'no class', 'order_total_too_large');
    }

    if (getenv('HD_SHOW_LOG')) {
        echo "--- what the controller logged ---\n", (string)@file_get_contents($logFile), "--- end ---\n";
    }
    @unlink($logFile);
    $total = $passed + count($failures);
    if ($failures) {
        foreach ($failures as $f) {
            echo 'FAIL ', $f, "\n";
        }
        echo 'Amount PG harness: FAIL ', count($failures), " of {$total} checks on PHP ", PHP_VERSION, "\n";
        return 1;
    }
    echo "Amount PG harness: PASS {$total} checks on PHP ", PHP_VERSION, " (scratch database dropped at exit)\n";
    return 0;
}

/**
 * A read through prepare/execute: Db::query() is the legacy method, not PDO's.
 *
 * @param PDO|Db $connection
 */
function hd_select($connection, string $sql): PDOStatement
{
    $statement = $connection->prepare($sql);
    $statement->execute();
    return $statement;
}
