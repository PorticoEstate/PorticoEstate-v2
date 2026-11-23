<?php

/**
 * Hermetic tests for who the citizen API takes for an application's owner, and for whose events the event lists add
 * to the public ones (task 48852; declaration forum/pe-general/49052; facts 48856, 48980):
 *
 *   ApplicationHelper::canViewApplication, and canModifyApplication / canAddComments / canUpdateStatus, which return
 *   what it returns. They gate GET/PUT/PATCH/DELETE on /bookingfrontend/applications/… (the application, its partials,
 *   documents, comments, status and hospitality orders) and the schedules' can_view_application and edit/cancel links.
 *   EventRepository::getUpcomingEvents (GET /bookingfrontend/events/upcoming) and booking_soevent::get_events_from_date
 *   (bookingfrontend.uieventsearch.upcoming_events), which add the logged-in organisation's own unlisted events.
 *
 * The rule (App\helpers\OwnerIdentifier): an identifier that is empty on either side never matches, and the
 * organisation number MinId2 gives every citizen without an organisation and every organisation with an ssn and no
 * number ('000000000') never matches either: it is the same for all of them.
 *
 *   php tests/application-owner/run.php
 *
 * The classes under test are the REAL code from HD_ROOT: ApplicationHelper, UserHelper (as the viewer's base class),
 * OwnerIdentifier, EventRepository, booking_soevent (class.soevent.inc.php). ApplicationHelper is built without its
 * constructor and given the viewer, a UserHelper subclass holding ssn, orgnr and organizations as a logged-in
 * UserHelper does (MinId2 fills them), without reading a session. Stubs: Sessions (the session id), App\Database\Db
 * (an in-memory SQLite database of events), the legacy db API soevent uses (limit_query/next_record/f over the same
 * database), phpgw::import_class and booking_socommon (only the db property). The stubs rewrite the PostgreSQL-only
 * parts of the event queries for SQLite (DISTINCT ON, jsonb_object_agg, OFFSET without LIMIT); the WHERE clause, which
 * is what is under test, is executed as the code builds it.
 *
 * P    every class under test is the HD_ROOT copy.
 * Application rows: each row asks all four ApplicationHelper methods and expects the same answer from each.
 * NO-BREAK (pass on the base tree AND on the fix):
 *   N1   the applicant, logged in with the application's ssn (session orgnr '000000000', no organisations): yes
 *   N2   a delegate whose session orgnr is the application's real number (:98): yes
 *   N3   a delegate whose organisation list holds the real number, session orgnr the placeholder (:105): yes
 *   N4   '000000000' stored, with an organisation id: a delegate of that organisation (its list entry '000000000'): yes
 *   N5   '' stored, with an organisation id: a delegate of that organisation (its list entry ''): yes
 *   N6   the guest session that made a partial (NEWPARTIAL1): yes
 *   N7   the application's secret, a guest from no session, '000000000' stored, no ssn, no organisation id: yes
 *   N8   '000000000' stored with the applicant's ssn: the applicant (ssn arm): yes
 *   N9   the organisation id arm compares as int: list org_id '77' (string) vs stored 77: yes
 *   N10  '0' is a number, not empty (OwnerIdentifier::isEmpty): a delegate of an organisation numbered '0' vs '0': yes
 *   X1   another citizen vs an ssn application: no
 *   X2   a delegate of another real organisation vs a '000000000' application with an organisation id: no
 *   X3   a guest from another session vs a partial: no
 * FIX (fail on the base tree, pass on the fix):
 *   F1   session orgnr '000000000', no organisations, another ssn vs '000000000' stored, no ssn, no org id (:98): no
 *   F2   a list holding a personal organisation ('000000000') vs the same application (:105): no
 *   F3   a list holding a number-less organisation ('') vs '' stored, another organisation (:105): no
 *   F4   a list entry NULL vs NULL stored (:105): no
 *   F5   viewer ssn '' vs stored ssn '' (:93): no; viewer ssn NULL vs stored NULL (:93): no
 *   F6   a partial stored with session_id NULL vs a request with no session (:84): no
 *   F7   the placeholder padded (' 000000000') on both sides, as session orgnr and as list entry: no
 *   F8   '000000000' stored with an organisation id: a personal-organisation holder of ANOTHER organisation: no
 * Event-list rows (each runs the real query and compares the event ids):
 *   EN1 / SN1  a guest: the public events only
 *   EN2 / SN2  a real number: the public events and that organisation's unlisted event
 *   EN3 / SN3  a real number, own events only: that organisation's unlisted event only
 *   EN4 / SN4  '0' as session orgnr: the public events only, as before (the lists took a falsy number as no login)
 *   EF1 / SF1  session orgnr '000000000': the public events only, not the events stored with '000000000' (FIX)
 *   EF2 / SF2  session orgnr '000000000', own events only: none (FIX; base: the events stored with '000000000')
 *   (E… = EventRepository, REST; S… = booking_soevent, the legacy search page)
 * List rows: GET /bookingfrontend/applications?include_organizations=true, ApplicationRepository::
 * getApplicationsBySsnAndOrganizations, the real query on SQLite; the lookups each listed application is decorated with
 * (dates, resources, orders, articles, age groups, audience, documents) find nothing. The viewer's organisations come
 * from a REAL UserHelper built from the session (UserHelper::fromSSN), over a stubbed session cache.
 *   LN1  a delegate of a real-number organisation: its applications by number and by id
 *   LN2  the applicant without organisations: their personal applications
 *   LN3  a delegate of an organisation numbered '0': its own by id; '0' is left out of the numbers, as before (!empty)
 *   LF1  a personal-organisation holder: its own application by id, NOT the other '000000000' applications (FIX)
 *
 * Red first: git archive <base> src | tar -x -C /tmp/ao-base && HD_ROOT=/tmp/ao-base php tests/application-owner/run.php
 * Environment (optional): HD_ROOT = tree holding src/ (default: this repo). vendor/ comes from HD_ROOT if it has one,
 * else from this repo; App\ classes always load from HD_ROOT.
 * Exit code: 0 all pass, 1 any failure (an arm that throws is a FAIL line, never a crash).
 */

namespace App\modules\phpgwapi\security {
    final class Sessions
    {
        private static ?self $instance = null;

        public static function getInstance(): self
        {
            return self::$instance ??= new self();
        }

        public function get_session_id()
        {
            return $GLOBALS['HARNESS_SESSION_ID'];
        }
    }
}

namespace App\modules\phpgwapi\services {
    /** The session cache, as the list rows' viewer has it: $GLOBALS['HARNESS_CACHE'][key]. */
    final class Cache
    {
        public static function session_get($module, $id)
        {
            return $GLOBALS['HARNESS_CACHE'][$id] ?? null;
        }

        public static function session_set($module, $id, $data)
        {
            $GLOBALS['HARNESS_CACHE'][$id] = $data;
        }

        public static function message_set($message, $type = 'message')
        {
        }
    }

    /** bookingfrontend's configuration: none set. */
    final class Config
    {
        public array $config_data = [];

        public function __construct($app = '', $location = null)
        {
        }

        public function read()
        {
            return $this->config_data;
        }
    }
}

namespace App\Database {
    /** A statement that finds nothing: what the list's per-application lookups (dates, orders, documents …) get. */
    final class HarnessEmptyStatement
    {
        public function bindValue(...$a)
        {
            return true;
        }

        public function bindParam(...$a)
        {
            return true;
        }

        public function execute($params = null)
        {
            return true;
        }

        public function fetchAll(...$a)
        {
            return [];
        }

        public function fetch(...$a)
        {
            return false;
        }

        public function fetchColumn(...$a)
        {
            return false;
        }

        public function rowCount()
        {
            return 0;
        }
    }

    /**
     * Stand-in for App\Database\Db: prepare() runs the event queries and the application list's own query on an
     * in-memory SQLite database. Any other statement (the lookups a listed application is decorated with) finds
     * nothing, and the legacy query()/next_record() (UserHelper's organisation name) finds no row.
     */
    final class Db
    {
        private static ?self $instance = null;
        public \PDO $pdo;

        public static function getInstance($dsn = '', $username = null, $password = null, $options = null): self
        {
            return self::$instance ??= new self();
        }

        private function __construct()
        {
            $this->pdo = new \PDO('sqlite::memory:');
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }

        public function query($sql, $line = '', $file = '')
        {
            $GLOBALS['HARNESS_SQL'][] = $sql;
            return true;
        }

        public function limit_query($sql, $offset, $line = '', $file = '', $num_rows = -1)
        {
            $GLOBALS['HARNESS_SQL'][] = $sql;
            return true;
        }

        public function next_record()
        {
            return false;
        }

        public function f($name, $strip_slashes = false)
        {
            return null;
        }

        /** The PostgreSQL-only parts of the event queries, as SQLite says them. The WHERE clause is left as it is. */
        public static function forSqlite(string $sql): string
        {
            $sql = preg_replace('/SELECT\s+DISTINCT\s+ON\s*\([^)]*\)/i', 'SELECT DISTINCT', $sql);
            $sql = str_replace('jsonb_object_agg(', 'json_group_object(', $sql);
            if (stripos($sql, 'LIMIT') === false) {
                $sql = preg_replace('/\sOFFSET\s+:start/i', ' LIMIT -1 OFFSET :start', $sql);
            }
            return $sql;
        }

        public function prepare($sql, $options = [])
        {
            $GLOBALS['HARNESS_SQL'][] = $sql;
            if (strpos($sql, 'bb_event') === false && strpos($sql, 'application_type') === false) {
                return new HarnessEmptyStatement();
            }
            return $this->pdo->prepare(self::forSqlite($sql), $options);
        }

        public function __call($method, $arguments)
        {
            return $this->pdo->$method(...$arguments);
        }
    }
}

namespace {
    use App\modules\bookingfrontend\helpers\ApplicationHelper;
    use App\modules\bookingfrontend\helpers\UserHelper;
    use App\modules\bookingfrontend\repositories\EventRepository;
    use Slim\Psr7\Factory\ServerRequestFactory;

    $HD_ROOT = rtrim(getenv('HD_ROOT') ?: dirname(__DIR__, 2), '/');
    // Warnings and deprecations are not failures here; each distinct one is listed as a NOTE at the end.
    $GLOBALS['HARNESS_NOTES'] = [];
    set_error_handler(function ($no, $msg, $file, $line) {
        $GLOBALS['HARNESS_NOTES'][basename($file) . ":{$line} {$msg}"] = true;
        return true;
    }, E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING | E_USER_NOTICE | E_USER_DEPRECATED);
    $vendor = is_file("{$HD_ROOT}/vendor/autoload.php") ? "{$HD_ROOT}/vendor/autoload.php" : dirname(__DIR__, 2) . '/vendor/autoload.php';
    require $vendor;
    // App\ from HD_ROOT, ahead of composer's map (which points at the repo the vendor/ belongs to)
    spl_autoload_register(function ($class) use ($HD_ROOT) {
        if (strncmp($class, 'App\\', 4) === 0) {
            $file = "{$HD_ROOT}/src/" . str_replace('\\', '/', substr($class, 4)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }, true, true);

    $GLOBALS['HARNESS_SESSION_ID'] = null;
    $GLOBALS['HARNESS_SQL'] = [];

    /** What the legacy class file needs to load: phpgw::import_class, and booking_socommon's db. */
    final class phpgw
    {
        public static function import_class($class)
        {
        }
    }

    abstract class booking_socommon
    {
        public $db;
    }

    /** The request parameters UserHelper reads while it is built (session_org_id, after): none given. */
    final class Sanitizer
    {
        public static function get_var($var_name, $value_type = 'string', $allowed_vars = 'any', $default = null)
        {
            return $default;
        }
    }

    /** The legacy db API booking_soevent::get_events_from_date uses, over the same SQLite database. */
    final class HarnessLegacyDb
    {
        private array $rows = [];
        private ?array $row = null;
        public array $sql = [];

        public function limit_query($sql, $offset, $line = '', $file = '', $num_rows = -1)
        {
            $this->sql[] = $sql;
            $stmt = App\Database\Db::getInstance()->pdo->query(App\Database\Db::forSqlite($sql));
            $this->rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        public function next_record()
        {
            $this->row = array_shift($this->rows);
            return $this->row !== null;
        }

        public function f($name, $strip_slashes = false)
        {
            return $this->row[$name] ?? null;
        }
    }

    /** A UserHelper as a request has it: ssn and orgnr when logged in, the organisations MinId2 listed. */
    final class HarnessViewer extends UserHelper
    {
        public function __construct(?string $ssn = null, ?string $orgnr = null, ?array $organizations = null)
        {
            $this->ssn = $ssn;
            $this->orgnr = $orgnr;
            $this->organizations = $organizations;
        }

        public function get_user_orgnr()
        {
            return $this->orgnr;
        }
    }

    const PH = '000000000';
    const REAL = '994239929';      // the delegate's organisation
    const REAL_B = '914994780';    // another real organisation
    const OWNER_SSN = '01010112345';
    const OTHER_SSN = '02020212345';

    /** An application row as ApplicationService::getApplicationById returns it (the columns the helper reads). */
    function app(int $id, array $fields): array
    {
        return array_merge(['id' => $id, 'status' => 'ACCEPTED', 'session_id' => "sess-{$id}", 'secret' => "secret-{$id}-abcdef",
            'contact_name' => 'Applicant', 'customer_identifier_type' => 'organization_number', 'customer_ssn' => null,
            'customer_organization_number' => null, 'customer_organization_id' => null], $fields);
    }

    $APPS = [
        'ssn' => app(601, ['customer_identifier_type' => 'ssn', 'customer_ssn' => OWNER_SSN]),
        'real' => app(602, ['customer_organization_number' => REAL, 'customer_organization_id' => 77, 'customer_ssn' => OTHER_SSN]),
        'ph' => app(603, ['customer_organization_number' => PH]),
        'ph_orgid' => app(604, ['customer_organization_number' => PH, 'customer_organization_id' => 88]),
        'empty' => app(605, ['customer_organization_number' => '']),
        'empty_orgid' => app(606, ['customer_organization_number' => '', 'customer_organization_id' => 99]),
        'null' => app(607, []),
        'partial' => app(608, ['status' => 'NEWPARTIAL1', 'session_id' => 'sess-owner', 'customer_identifier_type' => null]),
        'partial_nosess' => app(609, ['status' => 'NEWPARTIAL1', 'session_id' => null, 'customer_identifier_type' => null]),
        'ssn_empty' => app(610, ['customer_identifier_type' => 'ssn', 'customer_ssn' => '']),
        'ph_ssn' => app(611, ['customer_organization_number' => PH, 'customer_ssn' => OWNER_SSN]),
        'padded' => app(612, ['customer_organization_number' => ' ' . PH]),
        'real_orgid_str' => app(613, ['customer_organization_number' => REAL_B, 'customer_organization_id' => 77]),
        'zero' => app(614, ['customer_organization_number' => '0']),
        'zero_orgid' => app(615, ['customer_organization_number' => '0', 'customer_organization_id' => 31]),
    ];

    function helper(HarnessViewer $viewer): ApplicationHelper
    {
        $helper = (new \ReflectionClass(ApplicationHelper::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(ApplicationHelper::class, 'userHelper'))->setValue($helper, $viewer);
        return $helper;
    }

    /** @return array<string, bool> the four methods' answers */
    function decide(HarnessViewer $viewer, ?string $session, array $application, array $query = []): array
    {
        $GLOBALS['HARNESS_SESSION_ID'] = $session;
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'https://pe-api.test/')->withQueryParams($query);
        $h = helper($viewer);
        return [
            'view' => $h->canViewApplication($application, $request),
            'modify' => $h->canModifyApplication($application, $request),
            'comment' => $h->canAddComments($application, $request),
            'status' => $h->canUpdateStatus($application, $request),
        ];
    }

    $pass = 0;
    $fail = 0;
    function check(string $row, bool $ok, string $what, string $detail = ''): void
    {
        global $pass, $fail;
        $ok ? $pass++ : $fail++;
        echo ($ok ? 'PASS ' : 'FAIL ') . "{$row}  {$what}" . ($ok || $detail === '' ? '' : "  [{$detail}]") . "\n";
    }

    function arm(string $row, string $what, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            check($row, false, $what, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
        }
    }

    /** All four methods answer $expected for this viewer, session and application. */
    function owner(string $row, string $what, bool $expected, HarnessViewer $viewer, ?string $session, string $app, array $query = []): void
    {
        global $APPS;
        arm($row, $what, function () use ($row, $what, $expected, $viewer, $session, $app, $query, $APPS) {
            $got = decide($viewer, $session, $APPS[$app], $query);
            $ok = $got === ['view' => $expected, 'modify' => $expected, 'comment' => $expected, 'status' => $expected];
            check($row, $ok, $what . ': ' . ($expected ? 'yes' : 'no'),
                implode(' ', array_map(fn($k, $v) => "{$k}=" . ($v ? 'yes' : 'no'), array_keys($got), $got)));
        });
    }

    // ---------------------------------------------------------------------------------------------------- P
    arm('P', 'the classes under test', function () use ($HD_ROOT) {
        require_once "{$HD_ROOT}/src/modules/booking/inc/class.soevent.inc.php";
        $real = true;
        $where = [];
        foreach ([ApplicationHelper::class, UserHelper::class, App\helpers\OwnerIdentifier::class, EventRepository::class,
                     'booking_soevent'] as $class) {
            $file = realpath((new \ReflectionClass($class))->getFileName());
            $where[] = $file;
            $real = $real && strpos($file, realpath($HD_ROOT) . '/src/') === 0;
        }
        check('P', $real, "every class under test is the HD_ROOT copy ({$HD_ROOT})", implode(' ', $where));
    });

    // ---------------------------------------------------------------------------------------------------- applications
    $guest = new HarnessViewer();
    $applicant = new HarnessViewer(OWNER_SSN, PH, null);                    // a citizen without organisations (MinId2:65-68)
    $citizen = new HarnessViewer(OTHER_SSN, PH, null);                      // another one
    $delegateBy98 = new HarnessViewer('04040412345', REAL, [['org_id' => 77, 'orgnr' => REAL]]);
    $delegateBy105 = new HarnessViewer('04040412345', PH, [['org_id' => 55, 'orgnr' => PH], ['org_id' => 77, 'orgnr' => REAL]]);
    $personalOrg88 = new HarnessViewer('05050512345', PH, [['org_id' => 88, 'orgnr' => PH]]);
    $numberless99 = new HarnessViewer('06060612345', REAL_B, [['org_id' => 99, 'orgnr' => '']]);
    $orgIdString = new HarnessViewer('04040412345', REAL, [['org_id' => '77', 'orgnr' => REAL]]);
    $otherDelegate = new HarnessViewer('07070712345', REAL_B, [['org_id' => 66, 'orgnr' => REAL_B]]);

    owner('N1', 'the applicant by ssn (session orgnr 000000000)', true, $applicant, 'sess-x', 'ssn');
    owner('N2', 'a delegate, session orgnr = the real number (:98)', true, $delegateBy98, 'sess-x', 'real');
    owner('N3', 'a delegate, the real number in the list, session orgnr 000000000 (:105)', true, $delegateBy105, 'sess-x', 'real');
    owner('N4', '000000000 stored with org id 88: a delegate of 88 (org-id arm)', true, $personalOrg88, 'sess-x', 'ph_orgid');
    owner('N5', "'' stored with org id 99: a delegate of 99 (org-id arm)", true, $numberless99, 'sess-x', 'empty_orgid');
    owner('N6', 'the guest session that made the partial', true, $guest, 'sess-owner', 'partial');
    owner('N7', 'the secret, a guest without a session, 000000000 stored', true, $guest, null, 'ph', ['secret' => 'secret-603-abcdef']);
    owner('N8', "000000000 stored with the applicant's ssn: the applicant", true, $applicant, 'sess-x', 'ph_ssn');
    owner('N9', "org id arm as int: list org_id '77' vs stored 77", true, $orgIdString, 'sess-x', 'real_orgid_str');
    owner('N10', "'0' is a number, not empty: a delegate of an organisation numbered '0' vs '0' stored (:105)", true,
        new HarnessViewer('09090912345', REAL_B, [['org_id' => 31, 'orgnr' => '0']]), 'sess-x', 'zero');
    owner('X1', 'another citizen vs an ssn application', false, $citizen, 'sess-x', 'ssn');
    owner('X2', 'a delegate of another real organisation vs 000000000 with org id 88', false, $otherDelegate, 'sess-x', 'ph_orgid');
    owner('X3', 'a guest from another session vs a partial', false, $guest, 'sess-other', 'partial');

    owner('F1', 'session orgnr 000000000, no organisations, vs 000000000 stored, no ssn, no org id (:98)', false, $citizen, 'sess-x', 'ph');
    owner('F2', 'a personal organisation (000000000) in the list vs 000000000 stored (:105)', false,
        new HarnessViewer('05050512345', REAL_B, [['org_id' => 88, 'orgnr' => PH]]), 'sess-x', 'ph');
    owner('F3', "a number-less organisation ('') in the list vs '' stored (:105)", false, $numberless99, 'sess-x', 'empty');
    owner('F4', 'a list entry NULL vs NULL stored (:105)', false,
        new HarnessViewer('06060612345', REAL_B, [['org_id' => 97, 'orgnr' => null]]), 'sess-x', 'null');
    owner('F5', "viewer ssn '' vs stored ssn '' (:93)", false, new HarnessViewer('', REAL_B, null), 'sess-x', 'ssn_empty');
    owner('F5', 'viewer ssn NULL vs stored ssn NULL (:93)', false, new HarnessViewer(null, REAL_B, null), 'sess-x', 'null');
    owner('F6', 'a partial stored with session_id NULL vs a request with no session (:84)', false, $guest, null, 'partial_nosess');
    owner('F7', "' 000000000' as session orgnr vs ' 000000000' stored (:98)", false,
        new HarnessViewer(OTHER_SSN, ' ' . PH, null), 'sess-x', 'padded');
    owner('F7', "' 000000000' in the list vs ' 000000000' stored (:105)", false,
        new HarnessViewer(OTHER_SSN, REAL_B, [['org_id' => 96, 'orgnr' => ' ' . PH]]), 'sess-x', 'padded');
    owner('F8', '000000000 stored with org id 88: a personal-organisation holder of org 89', false,
        new HarnessViewer('08080812345', PH, [['org_id' => 89, 'orgnr' => PH]]), 'sess-x', 'ph_orgid');

    // ---------------------------------------------------------------------------------------------------- event lists
    $pdo = App\Database\Db::getInstance()->pdo;
    $pdo->exec('CREATE TABLE bb_event (id INTEGER PRIMARY KEY, active INTEGER, name TEXT, organizer TEXT, from_ TEXT, to_ TEXT,'
        . ' building_id INTEGER, building_name TEXT, activity_id INTEGER, include_in_list INTEGER, is_public INTEGER,'
        . ' customer_organization_id INTEGER, customer_organization_number TEXT, customer_ssn TEXT)');
    $pdo->exec('CREATE TABLE bb_building (id INTEGER PRIMARY KEY, name TEXT)');
    $pdo->exec('CREATE TABLE bb_event_resource (event_id INTEGER, resource_id INTEGER)');
    $pdo->exec('CREATE TABLE bb_resource (id INTEGER PRIMARY KEY, name TEXT, rescategory_id INTEGER, activity_id INTEGER)');
    $pdo->exec('CREATE TABLE bb_rescategory (id INTEGER PRIMARY KEY, name TEXT)');
    $pdo->exec('CREATE TABLE bb_activity (id INTEGER PRIMARY KEY, name TEXT)');
    $pdo->exec("INSERT INTO bb_building VALUES (10, 'Hall')");
    $pdo->exec("INSERT INTO bb_rescategory VALUES (3, 'Rom')");
    $pdo->exec("INSERT INTO bb_resource VALUES (5, 'Sal', 3, 1)");
    $pdo->exec("INSERT INTO bb_activity VALUES (1, 'Idrett')");
    $from = date('Y-m-d', strtotime('+10 days'));
    // 1 public; 2 stored with the placeholder, not listed; 3 the delegate's organisation, not listed; 4 '' and 5 '0',
    // not listed (a session orgnr is never '' or '0': the validator refuses both, and the lists took them as no login)
    foreach ([[1, 1, REAL_B], [2, 0, PH], [3, 0, REAL], [4, 0, ''], [5, 0, '0']] as [$id, $listed, $number]) {
        $pdo->prepare('INSERT INTO bb_event (id, active, name, organizer, from_, to_, building_id, building_name, activity_id,'
            . ' include_in_list, is_public, customer_organization_number) VALUES (?, 1, ?, ?, ?, ?, 10, ?, 1, ?, 1, ?)')
            ->execute([$id, "event {$id}", "org {$id}", "{$from} 1{$id}:00:00", "{$from} 1{$id}:30:00", 'Hall', $listed, $number]);
        $pdo->prepare('INSERT INTO bb_event_resource VALUES (?, 5)')->execute([$id]);
    }

    function restIds(bool $ownOnly, ?string $loggedInAs): array
    {
        $rows = (new EventRepository())->getUpcomingEvents(date('Y-m-d'), null, null, null, null, $ownOnly, $loggedInAs, 0, null);
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        sort($ids);
        return $ids;
    }

    function legacyIds(?bool $ownOnly, $loggedInAs): array
    {
        $so = (new \ReflectionClass('booking_soevent'))->newInstanceWithoutConstructor();
        $so->db = new HarnessLegacyDb();
        // as uieventsearch.upcoming_events passes them: '' for the filters the page leaves empty
        $rows = $so->get_events_from_date(date('Y-m-d'), '', [], '', '', $ownOnly, $loggedInAs, 0, 50);
        $ids = array_map(fn($r) => (int)$r['event_id'], $rows);
        sort($ids);
        return $ids;
    }

    function listed(string $row, string $what, callable $ids, array $expected): void
    {
        arm($row, $what, function () use ($row, $what, $ids, $expected) {
            $got = $ids();
            check($row, $got === $expected, $what . ': [' . implode(',', $expected) . ']', 'got [' . implode(',', $got) . ']');
        });
    }

    listed('EN1', 'REST, a guest', fn() => restIds(false, null), [1]);
    listed('EN2', 'REST, a real number', fn() => restIds(false, REAL), [1, 3]);
    listed('EN3', 'REST, a real number, own events only', fn() => restIds(true, REAL), [3]);
    listed('EN4', "REST, '0' as a session orgnr is still no login (as before): the public events", fn() => restIds(false, '0'), [1]);
    listed('EF1', 'REST, session orgnr 000000000', fn() => restIds(false, PH), [1]);
    listed('EF2', 'REST, session orgnr 000000000, own events only', fn() => restIds(true, PH), []);
    listed('SN1', 'legacy, a guest', fn() => legacyIds(null, null), [1]);
    listed('SN2', 'legacy, a real number', fn() => legacyIds(null, REAL), [1, 3]);
    listed('SN3', 'legacy, a real number, own events only', fn() => legacyIds(true, REAL), [3]);
    listed('SN4', "legacy, '0' as a session orgnr is still no login (as before): the public events", fn() => legacyIds(null, '0'), [1]);
    listed('SF1', 'legacy, session orgnr 000000000', fn() => legacyIds(null, PH), [1]);
    listed('SF2', 'legacy, session orgnr 000000000, own events only', fn() => legacyIds(true, PH), []);

    // ---------------------------------------------------------------------------------------------------- the list
    // GET /bookingfrontend/applications?include_organizations=true: ApplicationRepository::getApplicationsBySsnAndOrganizations
    // reads the organisations from a UserHelper built from the session (UserHelper::fromSSN), as MinId2 left them there.
    if (!defined('PHPGW_SERVER_ROOT')) {
        define('PHPGW_SERVER_ROOT', "{$HD_ROOT}/src/modules");
    }
    // the columns the list filters on, and those the Application model types (id_string, active, display_in_dashboard, type)
    $pdo->exec('CREATE TABLE bb_application (id INTEGER PRIMARY KEY, id_string TEXT, active INTEGER, display_in_dashboard INTEGER,'
        . ' type TEXT, status TEXT, created TEXT, session_id TEXT, secret TEXT, contact_name TEXT, customer_identifier_type TEXT,'
        . ' customer_ssn TEXT, customer_organization_number TEXT, customer_organization_id INTEGER)');
    $n = 0;
    foreach ($APPS as $a) {
        $pdo->prepare('INSERT INTO bb_application (id, id_string, active, display_in_dashboard, type, status, created, session_id,'
            . ' secret, contact_name, customer_identifier_type, customer_ssn, customer_organization_number, customer_organization_id)'
            . " VALUES (?, ?, 1, 1, 'application', ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$a['id'], (string)$a['id'], $a['status'], sprintf('2026-11-%02d 12:00:00', ++$n), $a['session_id'], $a['secret'],
                $a['contact_name'], $a['customer_identifier_type'], $a['customer_ssn'], $a['customer_organization_number'],
                $a['customer_organization_id']]);
    }

    /** The ids in the citizen's list, for a session that MinId2 logged in with $orgnr and these organisations. */
    function listIds(string $ssn, string $orgnr, ?array $organizations): array
    {
        $GLOBALS['HARNESS_CACHE'] = [
            UserHelper::ORGNR_SESSION_KEY => $orgnr,
            UserHelper::ORGID_SESSION_KEY => $organizations[0]['org_id'] ?? null,
            UserHelper::ORGARRAY_SESSION_KEY => $organizations === null ? null
                : array_map(fn($o) => $o + ['orgname' => "org {$o['org_id']}"], $organizations),
            UserHelper::USERARRAY_SESSION_KEY => ['ssn' => $ssn],
        ];
        $repo = (new \ReflectionClass(App\modules\bookingfrontend\repositories\ApplicationRepository::class))->newInstanceWithoutConstructor();
        foreach (['db' => App\Database\Db::getInstance(), 'userHelper' => new HarnessViewer($ssn, $orgnr, $organizations),
                     'articleRepository' => new App\modules\bookingfrontend\repositories\ArticleRepository(),
                     'resourceRepository' => (new \ReflectionClass(App\modules\bookingfrontend\repositories\ResourceRepository::class))->newInstanceWithoutConstructor()]
                 as $property => $value) {
            (new \ReflectionProperty(App\modules\bookingfrontend\repositories\ApplicationRepository::class, $property))->setValue($repo, $value);
        }
        $ids = array_map(fn($a) => (int)$a['id'], $repo->getApplicationsBySsnAndOrganizations($ssn, true));
        sort($ids);
        return $ids;
    }

    // N: a delegate of org 77 (number REAL) sees 602 (number and id) and 613 (id 77, another number); the applicant sees 601
    listed('LN1', 'list, a delegate of a real-number organisation (77)', fn() => listIds('04040412345', REAL,
        [['org_id' => 77, 'orgnr' => REAL]]), [602, 613]);
    listed('LN2', 'list, the applicant without organisations (personal applications)', fn() => listIds(OWNER_SSN, PH, null), [601]);
    // N: the list left a number '0' out before (!empty) and still does; organisation 31's own application comes by its id
    listed('LN3', "list, a delegate of an organisation numbered '0' (31): its own by id, not 614 stored '0'", fn() => listIds('09090912345', REAL_B,
        [['org_id' => 31, 'orgnr' => '0']]), [615]);
    // F: the holder of personal organisation 88 sees 604 (stored with id 88), not the other '000000000' applications
    listed('LF1', 'list, a personal-organisation holder (88): its own by id, no other 000000000 application', fn() => listIds('05050512345', PH,
        [['org_id' => 88, 'orgnr' => PH]]), [604]);

    if (getenv('HARNESS_LIST_FILES')) {
        foreach (get_included_files() as $file) {
            echo "FILE {$file}\n";
        }
    }
    foreach (array_keys($GLOBALS['HARNESS_NOTES']) as $note) {
        echo "NOTE {$note}\n";
    }
    echo "\n" . ($fail === 0 ? "ALL PASS {$pass}" : "FAIL {$fail} of " . ($pass + $fail)) . "\n";
    exit($fail === 0 ? 0 : 1);
}
