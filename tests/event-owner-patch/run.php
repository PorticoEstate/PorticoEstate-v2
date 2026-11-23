<?php

/**
 * Hermetic tests for who may change an event through PATCH /bookingfrontend/events/{id} (task 48753; operator ruling
 * forum/pe-general/48190 «fix the rule, but ensure it doesnt break anything»; pe-pm forum/pe-general/48812).
 *
 *   php tests/event-owner-patch/run.php
 *
 * EventService::checkEventOwnerShip compared the event's stored ssn with the viewer's (===) and looked the stored
 * organisation number up in the viewer's delegations (loose in_array). A guest has no ssn, so every event stored
 * without one was the guest's; a delegate of an organisation without a number had '' in the list, which equals a
 * stored '' or NULL. The owner arms now apply the serialiser's empty rule (OwnerIdentifier::isEmpty), and the org arm
 * also refuses MinId2's '000000000', the number every organisation with an ssn and no number gets.
 *
 * The classes under test are the REAL code from HD_ROOT: App\helpers\OwnerIdentifier (where the tree has it),
 * App\traits\SerializableTrait, bookingfrontend Event, EventService. EventService is built without its constructor
 * (which opens the database and reads the session) and given the viewer the way UserHelper holds it: ssn (null without
 * a login) and organizations (null, or rows with an 'orgnr'). Stubs: Translation (the private-event placeholder) and
 * App\Database\Db: an in-memory SQLite database (bb_event_resource, bb_participant) under the real EventRepository,
 * recording every SQL text it is given. SQLite stands in for PostgreSQL only for what is asked here: which rows a
 * statement touches, and whether a request value became SQL text.
 *
 * P   every class under test is the HD_ROOT copy.
 * NO-BREAK rows (pass on the base tree AND on the fix):
 *   N1  the owner by ssn, and by an organisation number (string, and an int in the list), may change the event
 *   N2  a logged-in non-owner may not (another ssn; another organisation)
 *   N3  the guest may not change an event stored with an ssn, or with a number and the ssn ''
 *   D1  pinned values (pe-pm 48749 (d)): '0'/'0' and 0/0 MATCH, in the ssn arm and in the org arm
 *   D3  the serialiser's owner arms on the same values: '0'/'0' and 0/0 show the secret, ''/'' and NULL/NULL do not
 *   N4  changing an event's resources through saveNewResourcesList: one added, an unshared one dropped, ids as strings
 * FIX rows (fail on the base tree, pass on the fix):
 *   F6  dropping a resource from one event leaves it linked to every other event (the DELETE went by resource_id only)
 *   F7  a resource id that is not an integer never becomes SQL text (the INSERT concatenated it; hermetic only)
 *   R1  EventService::resourceIdsFromRequest: positive integers (int, or digits only) within integer range, else null
 *   R2  EventController::updateEvent answers 400 for such a body after the owner check and before the write
 *   F1  the guest (ssn null, organizations null) and an event stored with a NULL ssn (with a number, '' or NULL):
 *       NOT the owner. On the base tree this is the 201 of forum/pe-tasks/48710 (23312 active NULL-ssn events).
 *   F2  (d) ''/'' and NULL/NULL do NOT match, in the ssn arm and in the org arm
 *   F3  a delegate of an organisation without a number ([''] / [null], also next to a real number) against a stored
 *       '' or NULL: NOT the owner
 *   F4  each side alone: a NULL stored number against a list holding 0 (NULL == 0 loosely); a stored '0' or 0
 *       against a list holding false or null ('0' == false, 0 == null)
 *   F5  '000000000' on either side: never the owner; the real number in the same list still is (in N1)
 *
 * Red first: git archive 38b813923 src | tar -x -C /tmp/op-base && HD_ROOT=/tmp/op-base php tests/event-owner-patch/run.php
 * Environment (optional): HD_ROOT = tree holding src/ (default: this repo). vendor/ comes from HD_ROOT if it has one,
 * else from this repo; App\ classes always load from HD_ROOT.
 * Exit code: 0 all pass, 1 any failure (an arm that throws is a FAIL line, never a crash).
 */

namespace App\modules\phpgwapi\services {
    final class Translation
    {
        private static ?self $instance = null;

        public static function getInstance(): self
        {
            return self::$instance ??= new self();
        }

        public function translate($key, $args = [], $force = false, $app = '')
        {
            return "[lang:{$app}.{$key}]";
        }
    }
}

namespace App\Database {
    /**
     * Stand-in for App\Database\Db: the same prepare() the repository calls, on an in-memory SQLite database holding
     * the two tables EventRepository writes, with every prepared SQL text recorded. ERRMODE_EXCEPTION, as
     * DatabaseServiceProvider opens the real one. ARRAY_AGG and array_to_json are given the meaning resourceIds()
     * needs from PostgreSQL (a JSON list of the group's values; NULL for no rows).
     */
    final class Db
    {
        private static ?self $instance = null;
        public \PDO $pdo;
        /** @var list<string> */
        public array $sql = [];

        public static function getInstance($dsn = '', $username = null, $password = null, $options = null): self
        {
            return self::$instance ??= new self();
        }

        public static function fresh(): self
        {
            return self::$instance = new self();
        }

        private function __construct()
        {
            $pdo = \PDO::connect('sqlite::memory:');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->createAggregate(
                'ARRAY_AGG',
                static function ($context, $rownumber, $value) { $context[] = $value; return $context; },
                static fn($context) => $context === null ? null : json_encode($context),
                1
            );
            $pdo->createFunction('array_to_json', static fn($value) => $value, 1);
            $pdo->exec('CREATE TABLE bb_event_resource (event_id INTEGER NOT NULL, resource_id INTEGER NOT NULL, PRIMARY KEY (event_id, resource_id))');
            $pdo->exec('CREATE TABLE bb_participant (id INTEGER PRIMARY KEY AUTOINCREMENT, reservation_type TEXT NOT NULL,'
                . ' reservation_id INTEGER NOT NULL, from_ TEXT, to_ TEXT, phone TEXT, email TEXT, quantity INTEGER NOT NULL DEFAULT 1, name TEXT)');
            $this->pdo = $pdo;
        }

        public function prepare(string $sql, array $options = []): \PDOStatement
        {
            $this->sql[] = $sql;
            return $this->pdo->prepare($sql, $options);
        }

        public function __call($method, $arguments)
        {
            return $this->pdo->$method(...$arguments);
        }
    }
}

namespace {

    error_reporting(E_ALL & ~E_DEPRECATED);

    final class OpWarnings
    {
        /** @var list<string> */
        public static array $list = [];
    }
    set_error_handler(static function (int $no, string $message, string $file = '', int $line = 0): bool {
        if ($no & (E_DEPRECATED | E_USER_DEPRECATED)) {
            return true;
        }
        OpWarnings::$list[] = $message . ' @' . basename($file) . ':' . $line;
        return true;
    });

    $root = getenv('HD_ROOT') ?: dirname(__DIR__, 2);
    $repo = dirname(__DIR__, 2);
    // Composer first, then App\ from the tree under test registered IN FRONT of it: composer prepends its own loader,
    // whose App\ points at this repo, so registering ours before it would test this repo's code, not HD_ROOT's.
    require (is_file($root . '/vendor/autoload.php') ? $root : $repo) . '/vendor/autoload.php';
    spl_autoload_register(static function (string $class) use ($root): void {
        if (str_starts_with($class, 'App\\')) {
            $file = $root . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }, true, true);

    use App\Database\Db;
    use App\modules\bookingfrontend\models\Event;
    use App\modules\bookingfrontend\repositories\EventRepository;
    use App\modules\bookingfrontend\services\EventService;

    const OP_SSN = '01010112345';
    const OP_ORG = '994239929';
    const OP_PLACEHOLDER = '000000000';

    $failures = [];
    $passed = 0;
    $armResult = [];
    $group = '-';
    $check = static function (string $name, bool $ok, string $detail = '') use (&$failures, &$passed, &$armResult, &$group): void {
        $armResult[$group] ??= true;
        if ($ok) {
            $passed++;
            return;
        }
        $armResult[$group] = false;
        $failures[] = $name . ($detail !== '' ? " — {$detail}" : '');
    };
    $same = static function (string $name, mixed $got, mixed $want) use ($check): void {
        $check($name, $got === $want, 'got ' . var_export($got, true) . ' want ' . var_export($want, true));
    };
    $arm = static function (string $name, callable $body) use (&$failures, &$group, &$armResult): void {
        $group = $name;
        $armResult[$group] ??= true;
        OpWarnings::$list = [];
        try {
            $body();
        } catch (\Throwable $e) {
            $armResult[$group] = false;
            $failures[] = "{$name} threw " . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine();
        }
        foreach (array_unique(OpWarnings::$list) as $w) {
            $armResult[$group] = false;
            $failures[] = "{$name} warning: {$w}";
        }
    };
    foreach (['src/traits/SerializableTrait.php', 'src/modules/bookingfrontend/models/Event.php',
              'src/modules/bookingfrontend/services/EventService.php', 'src/modules/bookingfrontend/repositories/EventRepository.php',
              'src/modules/bookingfrontend/controllers/EventController.php'] as $needed) {
        if (!is_file($root . '/' . $needed)) {
            echo 'FAIL harness cannot load the code under test — ', $needed, ' is missing under ', $root, "\n";
            echo "Event owner PATCH harness: FAIL 1 of 1 checks\n";
            exit(1);
        }
    }

    /**
     * The real checkEventOwnerShip for one viewer: ssn and organizations as UserHelper holds them (organizations is
     * null for a guest, else rows carrying an 'orgnr'), against the two columns getPartialEventObjectById reads.
     */
    $owns = static function (mixed $viewerSsn, ?array $viewerOrgNumbers, mixed $storedSsn, mixed $storedOrgNumber): bool {
        $service = (new \ReflectionClass(EventService::class))->newInstanceWithoutConstructor();
        $viewer = new \stdClass();
        $viewer->ssn = $viewerSsn;
        $viewer->organizations = $viewerOrgNumbers === null
            ? null
            : array_map(static fn($n) => ['org_id' => 1, 'orgnr' => $n, 'orgname' => 'Org'], $viewerOrgNumbers);
        $property = new \ReflectionProperty(EventService::class, 'bouser');
        $property->setValue($service, $viewer);
        return (bool)$service->checkEventOwnerShip([
            'id' => 48753, 'customer_ssn' => $storedSsn, 'customer_organization_number' => $storedOrgNumber,
            'from_' => '2030-01-01 10:00:00', 'to_' => '2030-01-01 11:00:00', 'participant_limit' => null,
        ]);
    };

    /**
     * A fresh in-memory database holding the given [event_id, resource_id] links, and the REAL service on the REAL
     * repository over it (the constructor is skipped: it opens the session; saveNewResourcesList needs neither).
     */
    $resourceService = static function (array $links): EventService {
        $db = Db::fresh();
        $insert = $db->pdo->prepare('INSERT INTO bb_event_resource (event_id, resource_id) VALUES (?, ?)');
        foreach ($links as [$eventId, $resourceId]) {
            $insert->execute([$eventId, $resourceId]);
        }
        $db->sql = [];
        $service = (new \ReflectionClass(EventService::class))->newInstanceWithoutConstructor();
        $service->repository = new EventRepository();
        return $service;
    };
    /** EventService::saveNewResourcesList, as updateEvent calls it for a PATCH body carrying resource_ids. */
    $saveResources = static function (EventService $service, int $eventId, mixed $resourceIds): void {
        (new \ReflectionMethod(EventService::class, 'saveNewResourcesList'))
            ->invoke($service, ['resource_ids' => $resourceIds], ['id' => $eventId]);
    };
    $links = static fn(): array => array_map(
        static fn(array $r) => [(int)$r[0], (int)$r[1]],
        Db::getInstance()->pdo->query('SELECT event_id, resource_id FROM bb_event_resource ORDER BY event_id, resource_id')->fetchAll(\PDO::FETCH_NUM)
    );

    // --- P: every class under test is the HD_ROOT copy (a red-first run against another tree means nothing otherwise)
    $arm('P', function () use ($root, $check) {
        $stub = (string)(new \ReflectionClass(Db::class))->getFileName();
        $check('P App\Database\Db is the in-memory stand-in of this harness', $stub === __FILE__, $stub);
        $want = realpath($root . '/src') . '/';
        $classes = [Event::class, EventService::class, EventRepository::class, \App\traits\SerializableTrait::class];
        if (is_file($root . '/src/helpers/OwnerIdentifier.php')) {
            $classes[] = \App\helpers\OwnerIdentifier::class;
        }
        foreach ($classes as $class) {
            $file = (string)realpath((string)(new \ReflectionClass($class))->getFileName());
            $check("P {$class} loads from HD_ROOT", str_starts_with($file, $want), $file);
        }
    });

    // --- NO-BREAK ------------------------------------------------------------------------------------
    $arm('N1', function () use ($owns, $same) {
        $same('N1 owner by ssn', $owns(OP_SSN, null, OP_SSN, null), true);
        $same('N1 owner by ssn, event also stored with a number', $owns(OP_SSN, null, OP_SSN, OP_ORG), true);
        $same('N1 owner by org nr', $owns(null, [OP_ORG], null, OP_ORG), true);
        $same('N1 owner by org nr, list as int (loose compare kept)', $owns(null, [(int)OP_ORG], null, OP_ORG), true);
        $same('N1 owner by org nr, list also holding a number-less org and the placeholder',
            $owns(OP_SSN, ['', null, OP_PLACEHOLDER, OP_ORG], null, OP_ORG), true);
        $same('N1 owner by org nr while logged in with another ssn', $owns('02020212345', [OP_ORG], OP_SSN, OP_ORG), true);
    });
    $arm('N2', function () use ($owns, $same) {
        $same('N2 another ssn', $owns('02020212345', null, OP_SSN, null), false);
        $same('N2 another org nr', $owns('02020212345', ['111111111'], null, OP_ORG), false);
        $same('N2 another ssn, event stored with a number', $owns('02020212345', ['111111111'], OP_SSN, OP_ORG), false);
    });
    $arm('N3', function () use ($owns, $same) {
        $same('N3 guest vs an ssn event', $owns(null, null, OP_SSN, null), false);
        $same("N3 guest vs an org event stored with ssn ''", $owns(null, null, '', OP_ORG), false);
    });
    $arm('D1', function () use ($owns, $same) {
        // 0 and '0' are values, not empty (pe-pm 48749 (d)); 31 organisations hold the number '0'.
        $same("D1 ssn arm '0'/'0' MATCH", $owns('0', null, '0', null), true);
        $same('D1 ssn arm 0/0 MATCH', $owns(0, null, 0, null), true);
        $same("D1 org arm '0'/['0'] MATCH", $owns(null, ['0'], null, '0'), true);
        $same('D1 org arm 0/[0] MATCH', $owns(null, [0], null, 0), true);
    });
    $arm('D3', function () use ($same) {
        // The serialiser's owner arms (SerializableTrait, the read lane) on the same values, on Event::$secret.
        $event = static fn(array $over): Event => new Event($over + [
            'id' => 48753, 'type' => 'event', 'name' => 'T48753', 'secret' => 's3cr3t48753', 'is_public' => 0,
            'customer_identifier_type' => 'ssn', 'customer_ssn' => null, 'customer_organization_number' => null,
        ]);
        $secret = static fn(Event $e, array $ctx) => $e->serialize($ctx)['secret'] ?? null;
        $bySsn = static fn($stored, $viewer) => $secret($event(['customer_ssn' => $stored]), ['user_ssn' => $viewer, 'organization_number' => null]);
        $byOrg = static fn($stored, $viewer) => $secret(
            $event(['customer_identifier_type' => 'organization_number', 'customer_organization_number' => $stored]),
            ['user_ssn' => null, 'organization_number' => $viewer]
        );
        $same("D3 trait ssn '0'/'0' MATCH", $bySsn('0', '0'), 's3cr3t48753');
        $same('D3 trait ssn 0/0 MATCH', $bySsn(0, 0), 's3cr3t48753');
        $same("D3 trait ssn ''/'' NO", $bySsn('', ''), null);
        $same('D3 trait ssn NULL/NULL NO', $bySsn(null, null), null);
        $same("D3 trait org '0'/['0'] MATCH", $byOrg('0', ['0']), 's3cr3t48753');
        $same('D3 trait org 0/[0] MATCH', $byOrg(0, [0]), 's3cr3t48753');
        $same("D3 trait org ''/[''] NO", $byOrg('', ['']), null);
        $same('D3 trait org NULL/[NULL] NO', $byOrg(null, [null]), null);
    });

    $arm('N4', function () use ($resourceService, $saveResources, $links, $same) {
        // Changing an event's resources still works where the base tree did it right: add one, drop one no other
        // event uses, ids given as ints or as strings of digits.
        $service = $resourceService([[1, 1], [1, 2], [2, 3]]);
        $saveResources($service, 1, [1, 2, 4]);
        $same('N4 a resource added', $links(), [[1, 1], [1, 2], [1, 4], [2, 3]]);
        $saveResources($service, 1, ['2', '4']);
        $same('N4 an unshared resource dropped', $links(), [[1, 2], [1, 4], [2, 3]]);
        $saveResources($service, 1, [2, 4]);
        $same('N4 the same list again changes nothing', $links(), [[1, 2], [1, 4], [2, 3]]);
    });

    // --- FIX -----------------------------------------------------------------------------------------
    $arm('F6', function () use ($resourceService, $saveResources, $links, $same) {
        // Event 2 shares resource 2 with event 1. Dropping it from event 1 must not drop it from event 2: the base
        // tree deleted bb_event_resource by resource_id alone.
        $service = $resourceService([[1, 1], [1, 2], [2, 2], [3, 2]]);
        $saveResources($service, 1, [1]);
        $same('F6 dropping a shared resource from event 1 leaves events 2 and 3 linked', $links(), [[1, 1], [2, 2], [3, 2]]);
        $service = $resourceService([[1, 1], [1, 2], [2, 1], [2, 2]]);
        $saveResources($service, 1, []);
        $same('F6 emptying event 1 leaves event 2 whole', $links(), [[2, 1], [2, 2]]);
    });
    $arm('F7', function () use ($resourceService, $saveResources, $links, $same, $check) {
        // A resource id that is not an integer must never become SQL text. Hermetic only: no such value is sent to a
        // running application. The base tree concatenated it into the VALUES list, so this one adds a row for event 999.
        $service = $resourceService([[1, 1]]);
        $crafted = '2), (999, 3';
        try {
            $saveResources($service, 1, [1, $crafted]);
        } catch (\PDOException $e) {
            // A refusal by the database is acceptable; what is checked is what was written and what SQL was sent.
        }
        $same('F7 no row for any event other than 1', array_values(array_filter($links(), static fn($l) => $l[0] !== 1)), []);
        $sent = implode("\n", Db::getInstance()->sql);
        $check('F7 the crafted value is in no SQL text sent', !str_contains($sent, '999'), $sent);
        $check('F7 the INSERT is prepared with placeholders', str_contains($sent, ':resource_id'), $sent);
    });
    $arm('R1', function () use ($same) {
        $service = (new \ReflectionClass(EventService::class))->newInstanceWithoutConstructor();
        $parse = static fn($v) => $service->resourceIdsFromRequest($v);
        $same('R1 ints', $parse([5, 7]), [5, 7]);
        $same('R1 strings of digits, a duplicate', $parse(['5', 7, '5']), [5, 7]);
        $same('R1 the empty list (drops every resource, as before)', $parse([]), []);
        $same('R1 the largest integer id', $parse(['2147483647']), [2147483647]);
        foreach ([
            "'abc'" => ['abc'], "'5abc'" => ['5abc'], '1.5' => [1.5], '1.0' => [1.0], 'true' => [true], 'null' => [null],
            '0' => [0], "'0'" => ['0'], '-3' => [-3], "'-3'" => ['-3'], "' 5'" => [' 5'], '[[1]]' => [[1]],
            "'2), (999, 3'" => ['2), (999, 3'], 'beyond integer' => [2147483648], "'99999999999'" => ['99999999999'],
            'a map' => ['a' => 1], 'a string' => '5', 'an int' => 5,
        ] as $label => $value) {
            $same("R1 refused: {$label}", $parse($value), null);
        }
    });
    $arm('R2', function () use ($root, $check) {
        // The controller refuses such a body with 400 after the owner check and before anything is written.
        $source = (string)file_get_contents($root . '/src/modules/bookingfrontend/controllers/EventController.php');
        $start = strpos($source, 'public function updateEvent(');
        $end = $start === false ? false : strpos($source, 'public function ', $start + 10);
        $body = $start === false ? '' : substr($source, $start, $end === false ? null : $end - $start);
        $at = static fn(string $needle) => strpos($body, $needle);
        $owner = $at('checkEventOwnerShip(');
        $parse = $at('resourceIdsFromRequest(');
        $refuse = $at("'Invalid resource_ids'");
        $write = $at('->updateEvent(');
        $check('R2 updateEvent parses resource_ids', $parse !== false);
        $check('R2 and answers 400 Invalid resource_ids', $refuse !== false && str_contains(substr($body, (int)$refuse, 120), '400'));
        $check('R2 after the owner check, before the write', $owner !== false && $parse !== false && $write !== false
            && $owner < $parse && $parse < $refuse && $refuse < $write);
        $check('R2 the parsed ids replace the body\'s', str_contains($body, "\$newData['resource_ids'] = \$resourceIds;"));
    });
    $arm('F1', function () use ($owns, $same) {
        $same('F1 guest vs an org event stored with ssn NULL', $owns(null, null, null, OP_ORG), false);
        $same('F1 guest vs ssn NULL, number NULL', $owns(null, null, null, null), false);
        $same("F1 guest vs ssn NULL, number ''", $owns(null, null, null, ''), false);
        $same('F1 guest with organizations [] vs ssn NULL', $owns(null, [], null, OP_ORG), false);
    });
    $arm('F2', function () use ($owns, $same) {
        $same("F2 ssn arm ''/'' NO", $owns('', null, '', null), false);
        $same('F2 ssn arm NULL/NULL NO', $owns(null, null, null, OP_ORG), false);
        $same("F2 ssn arm whitespace/whitespace NO", $owns(' ', null, ' ', null), false);
        $same("F2 org arm ''/[''] NO", $owns(OP_SSN, [''], '02020212345', ''), false);
        $same('F2 org arm NULL/[NULL] NO', $owns(OP_SSN, [null], '02020212345', null), false);
    });
    $arm('F3', function () use ($owns, $same) {
        $same("F3 delegate of a number-less org [''] vs stored NULL", $owns(OP_SSN, [''], null, null), false);
        $same("F3 delegate of a number-less org [null] vs stored ''", $owns(OP_SSN, [null], null, ''), false);
        $same("F3 delegate of a real and a number-less org vs stored ''", $owns(OP_SSN, [OP_ORG, ''], null, ''), false);
    });
    $arm('F4', function () use ($owns, $same) {
        $same('F4 stored NULL vs a list [0]', $owns(OP_SSN, [0], '02020212345', null), false);
        $same("F4 stored '' vs a list [false]", $owns(OP_SSN, [false], '02020212345', ''), false);
        // The list side alone: a stored value that is not empty, against an empty element that equals it loosely.
        $same("F4 stored '0' vs a list [false] ('0' == false)", $owns(OP_SSN, [false], '02020212345', '0'), false);
        $same('F4 stored 0 vs a list [null] (0 == null)', $owns(OP_SSN, [null], '02020212345', 0), false);
    });
    $arm('F5', function () use ($owns, $same) {
        $same("F5 '000000000'/['000000000'] NO", $owns(OP_SSN, [OP_PLACEHOLDER], null, OP_PLACEHOLDER), false);
        $same("F5 stored '000000000' vs ['000000000', a real number] NO", $owns(OP_SSN, [OP_PLACEHOLDER, OP_ORG], null, OP_PLACEHOLDER), false);
        $same("F5 stored '0' vs ['000000000'] NO (equal as numeric strings)", $owns(OP_SSN, [OP_PLACEHOLDER], null, '0'), false);
        $same("F5 stored '000000000' vs ['0'] NO", $owns(OP_SSN, ['0'], null, OP_PLACEHOLDER), false);
    });

    // --- report ----------------------------------------------------------------------------------------
    foreach ($armResult as $name => $ok) {
        printf("%s  %s\n", $ok ? 'PASS' : 'FAIL', $name);
    }
    foreach ($failures as $f) {
        echo 'FAIL ', $f, "\n";
    }
    $total = $passed + count($failures);
    if ($failures) {
        printf("Event owner PATCH harness: FAIL %d of %d checks on PHP %s\n", count($failures), $total, PHP_VERSION);
        exit(1);
    }
    printf("Event owner PATCH harness: PASS %d checks on PHP %s\n", $total, PHP_VERSION);
    exit(0);
}
