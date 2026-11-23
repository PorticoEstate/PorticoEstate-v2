<?php

/**
 * Hermetic tests for the legacy participant registration (bookingfrontend.uiparticipant.add, a public page with no
 * login): which registration a phone number finds, and what the request may write onto it (task 48864; facts 48857,
 * 48927; declaration forum/pe-general/48928).
 *
 *   php tests/participant-registration/run.php
 *
 * booking_soparticipant::get_previous_registration found the latest registration of the reservation whose stored phone
 * ENDED with the request's («phone LIKE '%{$phone}'», the phone in the SQL text). With % or _ in the phone, or a short
 * literal ending such as '1', that is somebody else's registration, and uiparticipant::add then wrote the request's
 * phone, email and check-in/out times onto it BY ITS ID. A registration is now found only by the same number
 * (soparticipant::phone_key: digits, without a leading 00 or the Norwegian country code), the statements are bound, and
 * a registration that is found keeps the phone AND the email it was stored with (task 48970: under phone = identity, a
 * request that only knows a number must not write contact data onto that registration; a new registration, when none is
 * found, takes the request's phone and email).
 *
 * The classes under test are the REAL code from HD_ROOT: booking_soparticipant (get_previous_registration, doValidate,
 * preValidate, get_number_of_participants, phone_key), bookingfrontend_uiparticipant::add and Sanitizer. Stubs:
 * - App\Database\Db: an in-memory SQLite bb_participant with the legacy query()/next_record()/f() and prepare(),
 *   recording every SQL text. SQLite stands in for PostgreSQL only for which rows a statement selects or writes, and
 *   whether a request value became SQL text.
 * - booking_socommon: validate() as socommon.inc.php:1413 (preValidate, the required fields, doValidate), update() as
 *   :1193 (every table field present in the entry, WHERE id) and add() as an INSERT of the same fields. The table
 *   fields are soparticipant's own list (its constructor, :9-19). The email validator is not mirrored.
 * - booking_uiparticipant (the parent page class), CreateObject, Cache, pretty_timestamp, the holiday and phone-widget
 *   helpers: they give add() a reservation, the configuration, and catch its redirect; an SMS is only recorded.
 * - the bo: boparticipant forwards these calls to the so (bocommon.inc.php:25 __call, :207 validate, :221 update).
 *
 * P   every file under test is the HD_ROOT copy.
 * NO-BREAK rows (pass on the base tree AND on the fix):
 *   N1  the stored phone, as stored, finds that registration
 *   N2  the 8 digits find the registration stored with +47
 *   N3  only the reservation's own registrations: the same number in another reservation, or another type, is not found
 *   N4  the latest registration of a number wins; checked out, a register_pre/in starts a new one (id null)
 *   N5  a number that is not registered finds nothing
 *   N6  POST register_in with the 8 digits of a pre-registered +47 number checks THAT registration in (from_ set), keeps
 *       its STORED email (the request's is written to no row) and changes no other row. N6 fails on 0af61b87b, which
 *       wrote the request's email onto the found registration (task 48970, ruling c-strict), and passes on the fix.
 *   N7  POST register_out with the stored phone checks that registration out and changes no other row
 *   N8  POST register_pre with a new number adds one registration and changes no other row
 * FIX rows (fail on the base tree, pass on the fix):
 *   F1  the phone '%' finds nothing                       F2  the phone '_' finds nothing
 *   F3  the phone '1' (an ending) finds nothing           F4  the phone '2221' (an ending of a stored number) finds nothing
 *   F5  a phone without digits never finds a registration stored without digits
 *   F6  a phone holding a quote is bound: no SQL text holds a request value, in the lookup and in doValidate
 *   F7  POST register_out with '%' changes NO registration (base: the latest one gets phone '%', the request's email
 *       and to_)
 *   F8  POST register_in with '1' changes NO existing registration (base: the latest registration ending in 1 gets
 *       phone '1', the request's email and from_)
 *   F9  a registration found by another format keeps its stored phone (N6's row; base writes the request's '9…')
 *   F10 through POST add(), no SQL text holds the request's phone or its reservation_type (the lookup, doValidate
 *       and both participant counts are bound)
 * N9 (passes on base too, there for a mutant): '091111111' does not find +4791111111 (a loose == between the two digit
 *   strings would compare them as numbers).
 * W rows (a deliberate widening; fail on the base tree): 0047 and spaced forms find the +47 registration.
 *
 * Red first: git archive <base> src | tar -x -C /tmp/pr-base && HD_ROOT=/tmp/pr-base php tests/participant-registration/run.php
 * Environment (optional): HD_ROOT = tree holding src/ (default: this repo). vendor/ comes from HD_ROOT if it has one,
 * else from this repo. Exit code: 0 all pass, 1 any failure (an arm that throws is a FAIL line, never a crash).
 */

namespace App\Database {
    /**
     * Stand-in for App\Database\Db with the two APIs the legacy class uses: query()/next_record()/f() (as Db.php:866,
     * :1093, :1174: f() gives '' for a missing or NULL column) and prepare() (forwarded to PDO, as Db::__call does).
     */
    final class Db
    {
        public \PDO $pdo;
        /** @var list<string> */
        public array $sql = [];
        private ?\PDOStatement $result = null;
        private ?array $record = null;

        public function __construct()
        {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec('CREATE TABLE bb_participant (id INTEGER PRIMARY KEY AUTOINCREMENT, reservation_type TEXT NOT NULL,'
                . ' reservation_id INTEGER NOT NULL, from_ TEXT, to_ TEXT, phone TEXT, email TEXT,'
                . ' quantity INTEGER NOT NULL DEFAULT 1, name TEXT)');
            $this->pdo = $pdo;
        }

        public function query($sql, $line = '', $file = '')
        {
            $this->sql[] = $sql;
            $this->result = $this->pdo->query($sql);
            $this->record = null;
            return $this->result;
        }

        public function next_record()
        {
            $row = $this->result ? $this->result->fetch(\PDO::FETCH_ASSOC) : false;
            $this->record = $row ?: null;
            return (bool)$row;
        }

        public function f($name, $strip_slashes = false)
        {
            return isset($this->record[$name]) ? $this->record[$name] : '';
        }

        public function prepare($sql, $options = [])
        {
            $this->sql[] = $sql;
            return $this->pdo->prepare($sql, $options);
        }
    }
}

namespace App\modules\phpgwapi\services {
    final class Cache
    {
        /** @var list<string> */
        public static array $messages = [];

        public static function system_get($module, $key)
        {
            return [];
        }

        public static function system_set($module, $key, $value)
        {
        }

        public static function message_set($message, $type = 'message')
        {
            self::$messages[] = (string)$message;
        }
    }

    /** Sanitizer::get_ip_address reads the server settings (trusted proxies): none here. */
    final class Settings
    {
        private static ?self $instance = null;

        public static function getInstance(): self
        {
            return self::$instance ??= new self();
        }

        public function get($section)
        {
            return [];
        }
    }
}

namespace {
    $HD_ROOT = rtrim(getenv('HD_ROOT') ?: dirname(__DIR__, 2), '/');
    // The legacy page reads variables it never set ($resource_participant_limit, ...). Warnings are not failures here;
    // each distinct one is listed as a NOTE at the end, with its file and line, so a new one is visible.
    $GLOBALS['HARNESS_NOTES'] = [];
    set_error_handler(function ($no, $msg, $file, $line) {
        $GLOBALS['HARNESS_NOTES'][basename($file) . ":{$line} {$msg}"] = true;
        return true;
    }, E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING | E_USER_NOTICE | E_USER_DEPRECATED);
    $vendor = is_file("{$HD_ROOT}/vendor/autoload.php") ? "{$HD_ROOT}/vendor/autoload.php" : dirname(__DIR__, 2) . '/vendor/autoload.php';
    require $vendor;

    final class HarnessRedirect extends \RuntimeException
    {
    }

    final class phpgw
    {
        public static function import_class($class)
        {
        }
    }

    final class phpgwapi_datetime
    {
        public static function get_holidays($year)
        {
            return [];
        }
    }

    final class phpgwapi_jquery
    {
        public static function init_intl_tel_input($target)
        {
        }
    }

    function lang($key, ...$args)
    {
        return (string)$key;
    }

    function pretty_timestamp($value)
    {
        return (string)$value;
    }

    /** What add() creates: the configuration, the reservation, the resource limit, and the SMS service (recorded). */
    function createObject($class, ...$args)
    {
        switch ($class) {
            case 'phpgwapi.config':
                return new class {
                    public function read()
                    {
                        return $GLOBALS['HARNESS_CONFIG'];
                    }
                };
            case 'booking.soresource':
                return new class {
                    public function get_participant_limit($resources, $flag = false)
                    {
                        return ['results' => []];
                    }
                };
            case 'sms.sms':
                return new class {
                    public function websend2pv($account, $to, $message)
                    {
                        $GLOBALS['HARNESS_SMS'][] = $to;
                        return true;
                    }
                };
        }
        if (strpos($class, 'booking.bo') === 0) {
            return new class {
                public function read_single($id)
                {
                    return $GLOBALS['HARNESS_RESERVATION'];
                }
            };
        }
        throw new \LogicException("harness: createObject('{$class}') is not stubbed");
    }

    final class booking_errorstack extends \ArrayObject
    {
    }

    /**
     * booking_socommon as far as booking_soparticipant uses it: the db, and validate()/update()/add() as the real
     * class does them for this table (see the file comment).
     */
    abstract class booking_socommon
    {
        /** soparticipant's fields (its constructor): the table fields update()/add() write when the entry has them. */
        public const TABLE_FIELDS = ['reservation_type', 'reservation_id', 'from_', 'to_', 'phone', 'email', 'quantity'];
        public const REQUIRED = ['reservation_type', 'reservation_id', 'phone', 'quantity'];

        protected $db;
        public $userSettings = ['account_id' => 0];
        public $account;

        public function __construct()
        {
        }

        public function validate(&$entity)
        {
            $errors = new booking_errorstack();
            $this->preValidate($entity);
            foreach (self::REQUIRED as $field) {
                if (!isset($entity[$field]) || $entity[$field] === '' || $entity[$field] === null) {
                    $errors[$field] = "Field {$field} is required";
                }
            }
            $this->doValidate($entity, $errors);
            return $errors->getArrayCopy();
        }

        protected function preValidate(&$entity)
        {
        }

        protected function doValidate($entity, booking_errorstack $errors)
        {
        }

        public function update($entry)
        {
            $id = (int)$entry['id'];
            $values = array_intersect_key($entry, array_flip(self::TABLE_FIELDS));
            $set = implode(', ', array_map(fn($k) => "{$k} = :{$k}", array_keys($values)));
            $GLOBALS['HARNESS_WRITES'][] = ['update', $id];
            $stmt = $this->db->pdo->prepare("UPDATE bb_participant SET {$set} WHERE id = :id");
            $stmt->execute($values + ['id' => $id]);
            return ['id' => $id];
        }

        public function add($entry)
        {
            $values = array_intersect_key($entry, array_flip(self::TABLE_FIELDS));
            $cols = implode(', ', array_keys($values));
            $marks = implode(', ', array_map(fn($k) => ":{$k}", array_keys($values)));
            $GLOBALS['HARNESS_WRITES'][] = ['add', null];
            $this->db->pdo->prepare("INSERT INTO bb_participant ({$cols}) VALUES ({$marks})")->execute($values);
            return ['id' => (int)$this->db->pdo->lastInsertId()];
        }
    }

    /** The page class bookingfrontend_uiparticipant extends: only what add() calls on it. */
    class booking_uiparticipant
    {
        public $bo;
        public $userSettings = ['preferences' => ['common' => ['timezone' => 'UTC']]];
        public $serverSettings = [];
        public $account = 0;
        public static array $flashed = [];

        public static function redirect($params)
        {
            throw new HarnessRedirect('redirect');
        }

        public function flash_form_errors($errors)
        {
            self::$flashed = (array)$errors;
        }

        public static function add_javascript(...$args)
        {
        }

        public static function render_template_xsl($template, $data)
        {
        }

        public static function link($params)
        {
            return '';
        }
    }

    /** boparticipant as add() reaches it: bocommon forwards to the so (__call, validate by reference, update, add). */
    final class HarnessBo
    {
        public function __construct(public booking_soparticipant $so)
        {
        }

        public function get_previous_registration(...$args)
        {
            return $this->so->get_previous_registration(...$args);
        }

        public function get_number_of_participants(...$args)
        {
            return $this->so->get_number_of_participants(...$args);
        }

        public function validate(&$entity)
        {
            return $this->so->validate($entity);
        }

        public function update($entity)
        {
            return $this->so->update($entity);
        }

        public function add($entity)
        {
            return $this->so->add($entity);
        }
    }

    $files = [
        'soparticipant' => "{$HD_ROOT}/src/modules/booking/inc/class.soparticipant.inc.php",
        'uiparticipant' => "{$HD_ROOT}/src/modules/bookingfrontend/inc/class.uiparticipant.inc.php",
        'Sanitizer' => "{$HD_ROOT}/src/helpers/Sanitizer.php",
    ];
    foreach ($files as $path) {
        require_once $path;
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
            check($row, false, $what, get_class($e) . ': ' . $e->getMessage());
        }
    }

    /**
     * A fresh database with the fixture registrations, and the so on it. Reservation allocation 1001 holds the rows
     * the lookups choose between; allocation 1002 and event 1001 hold the same number as row 'one' (scope).
     * @return array{0: booking_soparticipant, 1: App\Database\Db, 2: array<string,int>}
     */
    function fixture(): array
    {
        $db = new App\Database\Db();
        $rows = [
            // name          type          res   phone            email                    from_                  to_
            'scope_other' => ['allocation', 1002, '+4791111111', 'scope@example.invalid', null, null],
            'scope_type' => ['event', 1001, '+4791111111', 'scope2@example.invalid', null, null],
            'nodigit' => ['allocation', 1001, 'abc', 'nodigit@example.invalid', null, null],
            'one' => ['allocation', 1001, '+4791111111', 'one@example.invalid', null, null],
            'two' => ['allocation', 1001, '+4792222221', 'two@example.invalid', '2026-01-01 10:00:00', null],
            'bare' => ['allocation', 1001, '93333333', 'bare@example.invalid', null, null],
            'four_out' => ['allocation', 1001, '+4794444444', 'four@example.invalid', '2026-01-01 10:00:00', '2026-01-01 11:00:00'],
            'four_in' => ['allocation', 1001, '+4794444444', 'four@example.invalid', '2026-01-01 12:00:00', null],
            'five_out' => ['allocation', 1001, '+4795555555', 'five@example.invalid', '2026-01-01 10:00:00', '2026-01-01 11:00:00'],
            'three' => ['allocation', 1001, '+4796666661', 'three@example.invalid', null, null],
        ];
        $ids = [];
        $stmt = $db->pdo->prepare('INSERT INTO bb_participant (reservation_type, reservation_id, phone, email, from_, to_, quantity)'
            . ' VALUES (?, ?, ?, ?, ?, ?, 1)');
        foreach ($rows as $name => $row) {
            $stmt->execute($row);
            $ids[$name] = (int)$db->pdo->lastInsertId();
        }
        $so = (new \ReflectionClass('booking_soparticipant'))->newInstanceWithoutConstructor();
        $prop = new \ReflectionProperty('booking_socommon', 'db');
        $prop->setValue($so, $db);
        $db->sql = [];
        return [$so, $db, $ids];
    }

    function all_rows(App\Database\Db $db): array
    {
        $out = [];
        foreach ($db->pdo->query('SELECT * FROM bb_participant ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['id']] = $row;
        }
        return $out;
    }

    /** The id get_previous_registration returns, as an int (0 when nothing is found: '' or null). */
    function found_id(booking_soparticipant $so, string $phone, string $register_type = 'register_in', string $type = 'allocation', int $res = 1001): int
    {
        return (int)($so->get_previous_registration($type, $res, $phone, $register_type)['id'] ?? 0);
    }

    /**
     * POST to the real bookingfrontend_uiparticipant::add for allocation 1001, which starts in 3 hours (the form is open).
     * @return array{ids: array<string,int>, rows_before: array, rows_after: array, sql: list<string>, sms: list<string>, redirected: bool, errors: array}
     */
    function post_add(array $request): array
    {
        [$so, $db, $ids] = fixture();
        $now = time();
        $GLOBALS['HARNESS_CONFIG'] = ['participant_limit' => 100, 'participant_limit_sms' => true];
        $GLOBALS['HARNESS_RESERVATION'] = [
            'id' => 1001, 'name' => 'Harness', 'resources' => [1], 'participant_limit' => 100,
            'from_' => gmdate('Y-m-d H:i:s', $now + 3 * 3600), 'to_' => gmdate('Y-m-d H:i:s', $now + 4 * 3600),
        ];
        $GLOBALS['HARNESS_SMS'] = [];
        $GLOBALS['HARNESS_WRITES'] = [];
        booking_uiparticipant::$flashed = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
        $_GET = [];
        $_POST = $_REQUEST = $request + ['reservation_type' => 'allocation', 'reservation_id' => '1001', 'quantity' => '1'];

        $ui = (new \ReflectionClass('bookingfrontend_uiparticipant'))->newInstanceWithoutConstructor();
        $ui->bo = new HarnessBo($so);
        $ui->resource_bo = (object)['so' => new class {
            public function read($params)
            {
                return ['results' => []];
            }
        }];
        $ui->group_bo = null;
        (new \ReflectionProperty('bookingfrontend_uiparticipant', 'module'))->setValue($ui, 'bookingfrontend');

        $before = all_rows($db);
        $db->sql = [];
        $redirected = false;
        try {
            $ui->add();
        } catch (HarnessRedirect $e) {
            $redirected = true;
        }
        return [
            'ids' => $ids, 'rows_before' => $before, 'rows_after' => all_rows($db), 'sql' => $db->sql, 'sms' => $GLOBALS['HARNESS_SMS'],
            'redirected' => $redirected, 'errors' => booking_uiparticipant::$flashed,
        ];
    }

    /** The ids of rows that existed before and differ after. */
    function changed(array $r): array
    {
        $ids = [];
        foreach ($r['rows_before'] as $id => $row) {
            if (($r['rows_after'][$id] ?? null) !== $row) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    function added(array $r): array
    {
        return array_values(array_diff_key($r['rows_after'], $r['rows_before']));
    }

    // ---------------------------------------------------------------------------------------------------- P
    $real = true;
    foreach ($files as $class => $path) {
        $loaded = match ($class) {
            'soparticipant' => (new \ReflectionClass('booking_soparticipant'))->getFileName(),
            'uiparticipant' => (new \ReflectionClass('bookingfrontend_uiparticipant'))->getFileName(),
            'Sanitizer' => (new \ReflectionClass('Sanitizer'))->getFileName(),
        };
        $real = $real && realpath($loaded) === realpath($path);
    }
    check('P', $real, "every file under test is the HD_ROOT copy ({$HD_ROOT})");

    // ---------------------------------------------------------------------------------------------- lookups
    arm('N1', 'the stored phone finds its registration', function () {
        [$so, , $ids] = fixture();
        check('N1', found_id($so, '+4791111111') === $ids['one'], 'the stored phone, as stored, finds that registration');
    });
    arm('N2', '8 digits find +47', function () {
        [$so, , $ids] = fixture();
        check('N2', found_id($so, '91111111') === $ids['one'], 'the 8 digits find the registration stored with +47');
    });
    arm('N3', 'reservation scope', function () {
        [$so, , $ids] = fixture();
        check('N3', found_id($so, '+4791111111', 'register_in', 'allocation', 1002) === $ids['scope_other']
            && found_id($so, '+4791111111', 'register_in', 'event', 1001) === $ids['scope_type']
            && found_id($so, '+4791111111', 'register_in', 'booking', 1001) === 0,
            'only the reservation\'s own registrations are found (another reservation, another type)');
    });
    arm('N4', 'latest wins; re-entry starts a new one', function () {
        [$so, , $ids] = fixture();
        $latest = found_id($so, '+4794444444', 'register_out') === $ids['four_in'];
        $reentry = found_id($so, '+4795555555', 'register_in') === 0 && found_id($so, '+4795555555', 'register_pre') === 0;
        check('N4', $latest && $reentry, 'the latest registration of a number wins; checked out, register_pre/in start a new one',
            "latest:" . var_export($latest, true) . " reentry:" . var_export($reentry, true));
    });
    arm('N5', 'unknown number', function () {
        [$so] = fixture();
        check('N5', found_id($so, '+4799999999') === 0, 'a number that is not registered finds nothing');
    });
    foreach ([['F1', '%'], ['F2', '_'], ['F3', '1'], ['F4', '2221']] as [$row, $phone]) {
        arm($row, "'{$phone}'", function () use ($row, $phone) {
            [$so] = fixture();
            $id = found_id($so, $phone);
            check($row, $id === 0, "the phone '{$phone}' finds no registration", "found id {$id}");
        });
    }
    arm('F5', 'no digits', function () {
        [$so, , $ids] = fixture();
        $hits = [];
        foreach (['abc', 'xyz', '%', '', '+'] as $phone) {
            if (found_id($so, $phone) === $ids['nodigit']) {
                $hits[] = $phone;
            }
        }
        check('F5', $hits === [], 'a phone without digits never finds the registration stored without digits',
            'found by ' . json_encode($hits));
    });
    arm('F6', 'bound', function () {
        [$so, $db] = fixture();
        $phone = "9'1\\%x";
        $error = '';
        try {
            $so->get_previous_registration('allocation', 1001, $phone, 'register_in');
            $entity = ['reservation_type' => 'allocation', 'reservation_id' => 1001, 'phone' => $phone,
                'register_type' => 'register_in', 'quantity' => 1];
            $so->validate($entity);
        } catch (\Throwable $e) {
            $error = get_class($e) . ': ' . $e->getMessage();
        }
        $leaks = array_values(array_filter($db->sql, fn($sql) => strpos($sql, "9'1") !== false || strpos($sql, '9\'\'1') !== false));
        check('F6', $error === '' && $leaks === [] && count($db->sql) >= 2,
            'a phone holding a quote is bound: the lookup and doValidate run, and no SQL text holds it',
            $error !== '' ? $error : (count($db->sql) . ' statements, holding it: ' . json_encode($leaks)));
    });
    arm('N9', '091111111', function () {
        [$so] = fixture();
        check('N9', found_id($so, '091111111') === 0, "'091111111' does not find +4791111111");
    });
    foreach ([['W1', '004791111111'], ['W2', '+47 911 11 111'], ['W3', '911 11 111']] as [$row, $phone]) {
        arm($row, $phone, function () use ($row, $phone) {
            [$so, , $ids] = fixture();
            check($row, found_id($so, $phone) === $ids['one'], "'{$phone}' finds the registration stored as +4791111111 (widened)");
        });
    }

    // ------------------------------------------------------------------------------------------ POST add()
    arm('N6', 'register_in by 8 digits', function () {
        $r = post_add(['register_type' => 'register_in', 'phone' => '91111111', 'email' => 'new@example.invalid']);
        $one = $r['ids']['one'];
        $row = $r['rows_after'][$one];
        $ok = $r['redirected'] && changed($r) === [$one] && added($r) === []
            && $row['from_'] !== null && $row['to_'] === null && $row['email'] === 'one@example.invalid'
            && array_filter($r['rows_after'], fn($after) => $after['email'] === 'new@example.invalid') === [];
        check('N6', $ok, 'POST register_in with the 8 digits of a pre-registered +47 number checks THAT registration in and keeps its stored email',
            json_encode(['redirected' => $r['redirected'], 'changed' => changed($r), 'added' => count(added($r)), 'row' => $row, 'errors' => $r['errors']]));
        check('F9', $row['phone'] === '+4791111111', 'the registration found by its 8 digits keeps its stored phone',
            "phone now '{$row['phone']}'");
    });
    arm('N7', 'register_out exact', function () {
        $r = post_add(['register_type' => 'register_out', 'phone' => '+4792222221', 'email' => 'two@example.invalid']);
        $two = $r['ids']['two'];
        $row = $r['rows_after'][$two];
        check('N7', $r['redirected'] && changed($r) === [$two] && added($r) === [] && $row['to_'] !== null
            && $row['phone'] === '+4792222221',
            'POST register_out with the stored phone checks that registration out and changes no other row',
            json_encode(['changed' => changed($r), 'added' => count(added($r)), 'errors' => $r['errors']]));
    });
    arm('N8', 'register_pre new number', function () {
        $r = post_add(['register_type' => 'register_pre', 'phone' => '+4798888888', 'email' => 'eight@example.invalid']);
        $new = added($r);
        check('N8', $r['redirected'] && changed($r) === [] && count($new) === 1 && $new[0]['phone'] === '+4798888888'
            && $new[0]['reservation_id'] == 1001,
            'POST register_pre with a new number adds one registration and changes no other row',
            json_encode(['changed' => changed($r), 'added' => $new, 'errors' => $r['errors']]));
    });
    arm('F7', "register_out '%'", function () {
        $r = post_add(['register_type' => 'register_out', 'phone' => '%', 'email' => 'attacker@example.invalid']);
        $hit = array_map(fn($id) => ['id' => $id, 'phone' => $r['rows_after'][$id]['phone'], 'email' => $r['rows_after'][$id]['email']], changed($r));
        check('F7', changed($r) === [] && added($r) === [],
            "POST register_out with the phone '%' changes no registration", 'changed ' . json_encode($hit));
    });
    arm('F8', "register_in '1'", function () {
        $r = post_add(['register_type' => 'register_in', 'phone' => '1', 'email' => 'attacker@example.invalid']);
        $hit = array_map(fn($id) => ['id' => $id, 'phone' => $r['rows_after'][$id]['phone'], 'email' => $r['rows_after'][$id]['email']], changed($r));
        check('F8', changed($r) === [], "POST register_in with the phone '1' changes no existing registration",
            'changed ' . json_encode($hit));
    });

    arm('F10', 'no request text in SQL', function () {
        $r = post_add(['register_type' => 'register_pre', 'phone' => '+4797777777', 'email' => 'seven@example.invalid']);
        $leaks = array_values(array_filter($r['sql'], fn($sql) => strpos($sql, '7777777') !== false
            || stripos($sql, 'allocation') !== false));
        check('F10', $r['redirected'] && count($r['sql']) >= 4 && $leaks === [],
            "through POST add(), no SQL text holds the request's phone or reservation_type",
            count($r['sql']) . ' statements, holding it: ' . json_encode($leaks));
    });

    foreach (array_keys($GLOBALS['HARNESS_NOTES']) as $note) {
        echo "NOTE {$note}\n";
    }
    echo "\n" . ($fail === 0 ? "ALL PASS {$pass}" : "FAIL {$fail} of " . ($pass + $fail)) . "\n";
    exit($fail === 0 ? 0 : 1);
}
