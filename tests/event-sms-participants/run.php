<?php

/**
 * Hermetic tests for the officer's "send SMS to the participants" button on an event
 * (GH #1327, task 36097): booking_uievent::send_sms_participants().
 *
 *   php tests/event-sms-participants/run.php
 *
 * The REAL class.uievent.inc.php runs, with the REAL Sanitizer and the REAL text of
 * booking_uicommon::sms_is_configured() (lifted out of class.uicommon.inc.php, so a change to the guard
 * moves this harness too). Everything that would touch the database, the session or a gateway is a stub:
 * CreateObject() is the seam (no production change), so the participants, the config row, the sms service
 * and the business object are recorded fakes. No SMS is ever sent, nothing reaches a database or storage/emails.
 *
 * What it proves (texts are asserted by KEY: lang() returns [lang:key]; the texts themselves are the
 * test_lang_files.php output and are checked there):
 *   A1  nobody to send to (0 participants, only blank phones): the «no participants» message, BEFORE the
 *       gateway is read, with the gateway blank and configured, and the sms service never created.
 *   A2  no gateway: the «not configured» message, the sms service never created; a get-only or send-only
 *       gateway counts as configured (control).
 *   A2b creating the sms service throws (an Exception, an Error): the same message, logged, nothing sent.
 *   A3  every send fails (empty gateway id, an Exception, a TypeError): status error, the failed numbers
 *       listed, no stray leading <br/>, NO comment on the event; a throw closes the open transaction, so the
 *       recipient AFTER a failing one is still sent.
 *   A4  mixed: ok, both lists, exactly ONE comment via add_single_comment, no read_single / update.
 *   A5  all ok: the same.
 *   A6  the comment insert throws after the send went out: the send's result is still returned, and logged.
 *   A9  an event that does not load (read_single gives nothing) no longer crashes after the SMS has gone.
 *   A10 the phone numbers come from a public form and reach the officer's DOM through .html(): escaped.
 *   A11 the audit comment's AUTHOR is the officer's full name (event_edit.xsl prints it verbatim), the same
 *       string the old add_comment() stored, never the account id.
 *   A7/A8 pins that are GREEN on master too: sms app disabled gives false; no send_sms gives «Nothing...».
 *   S   the REAL booking_boevent / booking_soevent add_single_comment on a recording Db (nothing is run):
 *       an author given is bound as a string, raw; an author omitted (or null) writes exactly the valueset
 *       it wrote before the parameter existed (the account id, PARAM_INT), through the bo too.
 *   C   every call of ->add_single_comment( in src/ and its argument count: the async access-request
 *       reminders (4 calls, 2 arguments, so the default path), the bo forward and this button.
 *
 * Up front (GH #1327 follow-up, task 36828): the event page itself says «no participants» and disables the box,
 * with the SAME rule the button uses.
 *   H   get_event_sms_recipients(): the one rule (trim, drop blanks, each number once) and the exact read filter,
 *       against an oracle written here, not taken from the code under test.
 *   P   sms_has_no_recipients_for_page(): true for nobody to text, false otherwise; SMS off = false and nothing
 *       is even created; a read that throws = false (the page still renders, enabled), logged.
 *   G   the page and the button AGREE on every participant set (the drift the shared helper exists to prevent).
 *   S   the source: the rule and the participant read exist ONCE in class.uievent.inc.php, both callers go through
 *       the helper, and edit() hands the flag to the template, asking for the event id it loaded and passing the
 *       answer on the right way round (`? 1 : 0`). Only this catches a second copy that agrees today.
 *   X   the REAL SMS block, cut verbatim from event_edit.xsl and run by XSLTProcessor on the real toXML output:
 *       flag 1 = textarea and button disabled and the message in #sms_receipt; flag 0 / absent = as before.
 *   I   the event id the button gets (task 36861): what the REAL Sanitizer hands the method for a missing, text,
 *       empty, fractional, zero, negative, array and nested-array id (I1); every id that is not a positive int
 *       gets the «no participants» message with NO read, NO object created and nothing sent, while participants
 *       exist that a read of event 0 or 1 would find (I2); a valid id is read as that int and sent as before, and
 *       the zero reads of I2 are shown to be countable (I3). An (int) cast would fail I2: (int)array = 1.
 *
 * Input goes through $_REQUEST: Sanitizer::get_var reads it (a POST-only fixture gives a clean wrong
 * «Nothing...» on every arm).
 *
 * Environment (all optional): HD_ROOT = tree holding src/ (default: this repo); mutate.py points it at a
 * copy carrying one deliberate defect and expects this harness to go red.
 *
 * Exit code: 0 all pass, 1 any failure (an arm that throws is a FAIL line, never a crash).
 */

namespace App\modules\phpgwapi\services {
    class Log
    {
        public function error($data)
        {
            \HdState::$log[] = $data;
        }
    }

    class Cache
    {
        public static function message_set($message, $type = 'message')
        {
            \HdState::$cacheMessages[] = [$message, $type];
        }
    }
}

namespace App\Database {
    /** The two calls the code under test makes; the transaction a failed send leaves open is modelled here. */
    class Db
    {
        private static ?Db $instance = null;
        public bool $open = false;
        public int $aborts = 0;

        public static function getInstance(): Db
        {
            return self::$instance ??= new Db();
        }

        public static function forget(): void
        {
            self::$instance = null;
        }

        public function get_transaction(): bool
        {
            return $this->open;
        }

        public function transaction_abort(): void
        {
            $this->aborts++;
            $this->open = false;
        }
    }
}

namespace App\modules\phpgwapi\controllers {
    class Locations
    {
        public function get_id($app, $location)
        {
            \HdState::$locationLookups[] = [$app, $location];
            return 4242;
        }
    }
}

namespace {

    use App\Database\Db;

    date_default_timezone_set('Europe/Oslo');
    error_reporting(E_ALL & ~E_DEPRECATED);

    $root = getenv('HD_ROOT') ?: dirname(__DIR__, 2);

    /** The officer the stub parent reports (current_account_fullname) and the account id it differs from. */
    const OFFICER = 'Harness Officer';
    const ACCOUNT_ID = 4711;

    // --- the state every stub records into --------------------------------------------------------------
    final class HdState
    {
        /** the logged-in officer's full name, as current_account_fullname() answers */
        public static string $officer = OFFICER;
        /** @var list<string> */
        public static array $constructed = [];
        /** @var list<array> */
        public static array $log = [];
        /** @var list<array> */
        public static array $cacheMessages = [];
        /** @var list<array> */
        public static array $locationLookups = [];
        /** @var list<array> */
        public static array $boCalls = [];
        /** @var list<array> every websend2pv attempt: [phone, content, account] */
        public static array $attempts = [];
        /** @var list<string> the phones whose send was really committed */
        public static array $committed = [];
        /** @var list<array> */
        public static array $readParams = [];
        /** @var list<array> */
        public static array $participants = [];
        /** @var array<string,string> gateway config of the 'common' section */
        public static array $gateway = [];
        /** @var array<string,string> phone => ok|empty|throw-runtime|throw-type */
        public static array $behaviour = [];
        public static ?\Closure $smsCreateThrows = null;
        public static ?\Closure $commentThrows = null;
        public static bool $eventLoads = true;
        public static ?\Closure $readThrows = null;
        /** @var array<string,string> key => text lang() answers instead of [lang:key] (the escape arm of X) */
        public static array $lang = [];

        public static function reset(): void
        {
            self::$constructed = self::$log = self::$cacheMessages = self::$locationLookups = [];
            self::$boCalls = self::$attempts = self::$committed = self::$readParams = [];
            self::$participants = self::$gateway = self::$behaviour = [];
            self::$smsCreateThrows = self::$commentThrows = self::$readThrows = null;
            self::$lang = [];
            self::$eventLoads = true;
            self::$officer = OFFICER;
            Db::forget();
        }
    }

    // --- the stubs -------------------------------------------------------------------------------------
    final class phpgw
    {
        public static function import_class($name): void
        {
        }
    }

    function lang($key, ...$args)
    {
        return HdState::$lang[$key] ?? "[lang:{$key}]";
    }

    function CreateObject($name, ...$args)
    {
        HdState::$constructed[] = $name;
        switch ($name) {
            case 'booking.soparticipant':
                return new HdSoParticipant();
            case 'admin.soconfig':
                return new HdSoConfig();
            case 'sms.sms':
                if (HdState::$smsCreateThrows) {
                    throw (HdState::$smsCreateThrows)();
                }
                return new HdSms();
        }
        throw new \LogicException("harness: unexpected CreateObject({$name})");
    }

    final class HdSoParticipant
    {
        public function read($params)
        {
            HdState::$readParams[] = $params;
            if (HdState::$readThrows) {
                throw (HdState::$readThrows)();
            }
            return ['results' => array_map(static fn($phone) => ['phone' => $phone], HdState::$participants)];
        }
    }

    final class HdSoConfig
    {
        public function read()
        {
            return ['common' => HdState::$gateway];
        }
    }

    /** websend2pv as far as the code under test can see it, including the transaction a throw leaves open. */
    final class HdSms
    {
        public function websend2pv($account_id, $phone, $content)
        {
            HdState::$attempts[] = [$phone, $content, $account_id];
            $db = Db::getInstance();
            switch (HdState::$behaviour[$phone] ?? 'ok') {
                case 'empty':
                    return [['']];
                case 'throw-runtime':
                    $db->open = true;
                    throw new \RuntimeException('gateway refused');
                case 'throw-type':
                    $db->open = true;
                    throw new \TypeError('gateway returned the wrong type');
            }
            // while a transaction is still open the send is never committed (what a leaked one costs)
            if ($db->open) {
                return [[0]];
            }
            HdState::$committed[] = $phone;
            return [[1000 + count(HdState::$committed)]];
        }
    }

    /** booking_boevent as far as send_sms_participants can see it. */
    final class HdBo
    {
        public function add_single_comment($event_id, $comment, $type = 'comment', $author = null)
        {
            HdState::$boCalls[] = ['add_single_comment', $event_id, $comment, $type, $author];
            if (HdState::$commentThrows) {
                throw (HdState::$commentThrows)();
            }
            return 1;
        }

        public function read_single($id)
        {
            HdState::$boCalls[] = ['read_single', $id];
            return HdState::$eventLoads ? ['id' => $id, 'comments' => []] : null;
        }

        /** authorize_write cannot authorize an array with no id: it throws (fact 36021) */
        public function update($event)
        {
            HdState::$boCalls[] = ['update', $event];
            if (!isset($event['id'])) {
                throw new \InvalidArgumentException('Missing id for authorization');
            }
            return [];
        }
    }

    /** The Db as far as add_single_comment can see it: the insert is recorded, never run. */
    final class HdRecDb
    {
        /** @var list<array{0:string,1:array}> [sql, valueset] */
        public array $inserts = [];

        public function insert($sql, $valueset, $line = '', $file = '')
        {
            $this->inserts[] = [$sql, $valueset];
            return true;
        }

        /** What Db::db_addslashes does: the PDO-quoted string without its outer quotes (a single quote doubles). */
        public function db_addslashes($string)
        {
            return ($string === '' || $string === null) ? '' : str_replace("'", "''", $string);
        }
    }

    /** The parents of the two REAL classes: only the properties add_single_comment reads. */
    class booking_socommon
    {
        protected $db;
        protected $userSettings;
    }

    class booking_bocommon_authorized
    {
        public $so;
    }

    /** What the id arms of the button carry when the request has no id at all (a value no request can send). */
    const NO_ID = "\0no id in the request";

    /** The button's request for an event id as the request carries it ($carried = NO_ID: no id key at all). */
    function hd_id_request($carried): array
    {
        return ($carried === NO_ID ? [] : ['id' => $carried]) + ['send_sms' => '1', 'sms_content' => 'Hei'];
    }

    /** The number of top-level arguments of the call whose "(" ends just before $from (quotes and nesting respected). */
    function hd_count_args(string $src, int $from): int
    {
        $depth = 0;
        $args = 0;
        $seen = false;
        for ($i = $from, $n = strlen($src); $i < $n; $i++) {
            $c = $src[$i];
            if ($c === "'" || $c === '"') {
                $seen = true;
                for ($i++; $i < $n && $src[$i] !== $c; $i++) {
                    if ($src[$i] === '\\') {
                        $i++;
                    }
                }
                continue;
            }
            if ($c === '(' || $c === '[' || $c === '{') {
                $depth++;
                $seen = true;
            } elseif ($c === ')' || $c === ']' || $c === '}') {
                if ($depth === 0) {
                    return $seen ? $args + 1 : 0;
                }
                $depth--;
            } elseif ($c === ',' && $depth === 0) {
                $args++;
            } elseif (!ctype_space($c)) {
                $seen = true;
            }
        }
        return -1; // never closed
    }

    // --- the real guard, the real Sanitizer, the real class ---------------------------------------------
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

    $fatal = static function (string $why): never {
        echo 'FAIL harness cannot load the code under test — ', $why, "\n";
        echo "Event SMS harness: FAIL 1 of 1 checks\n";
        exit(1);
    };

    $common = @file_get_contents($root . '/src/modules/booking/inc/class.uicommon.inc.php');
    if ($common === false) {
        $fatal('class.uicommon.inc.php is not readable under ' . $root);
    }
    $start = strpos($common, 'protected function sms_is_configured()');
    $end = $start === false ? false : strpos($common, "\n\t}\n", $start);
    if ($start === false || $end === false) {
        $fatal('sms_is_configured() was not found in class.uicommon.inc.php');
    }
    $guardSource = substr($common, $start, $end + 3 - $start);

    // The parent: only what the method under test inherits. sms_is_configured() is the REAL text.
    eval('use App\modules\phpgwapi\controllers\Locations;
        class booking_uicommon
        {
            public $bo;
            public $apps = [];
            public static function current_account_id() { return 7; }
            public static function current_account_fullname() { return \HdState::$officer; }
            ' . $guardSource . '
        }');

    foreach (['src/helpers/Sanitizer.php', 'src/modules/booking/inc/class.uievent.inc.php',
              'src/modules/booking/inc/class.boevent.inc.php', 'src/modules/booking/inc/class.soevent.inc.php'] as $needed) {
        if (!is_file($root . '/' . $needed)) {
            $fatal($needed . ' is missing under ' . $root);
        }
    }
    require_once $root . '/src/helpers/Sanitizer.php';
    require_once $root . '/src/modules/booking/inc/class.uievent.inc.php';
    require_once $root . '/src/modules/booking/inc/class.soevent.inc.php';
    require_once $root . '/src/modules/booking/inc/class.boevent.inc.php';

    // --- driving it ------------------------------------------------------------------------------------
    const EVENT_ID = 84227;
    const P1 = '91000001';
    const P2 = '91000002';
    const P3 = '91000003';
    const CONFIGURED = ['gateway_module_send' => 'gnokii'];

    /**
     * One request through the REAL method. Options: participants (phones), gateway (config 'common'),
     * behaviour (phone => ok|empty|throw-runtime|throw-type), smsCreateThrows, commentThrows (closures
     * giving a fresh Throwable), readThrows (same), eventLoads, apps, request (replaces the default send-sms request).
     *
     * @return array{0:mixed,1:?\Throwable}
     */
    $run = static function (array $o = []): array {
        HdState::reset();
        HdState::$participants = $o['participants'] ?? [];
        HdState::$gateway = $o['gateway'] ?? CONFIGURED;
        HdState::$behaviour = $o['behaviour'] ?? [];
        HdState::$smsCreateThrows = $o['smsCreateThrows'] ?? null;
        HdState::$commentThrows = $o['commentThrows'] ?? null;
        HdState::$eventLoads = $o['eventLoads'] ?? true;
        HdState::$readThrows = $o['readThrows'] ?? null;
        HdState::$officer = $o['officer'] ?? OFFICER;

        $_GET = $_POST = [];
        $_REQUEST = $o['request'] ?? ['id' => (string)EVENT_ID, 'send_sms' => '1', 'sms_content' => 'Hei'];

        $ui = (new \ReflectionClass('booking_uievent'))->newInstanceWithoutConstructor();
        $ui->bo = new HdBo();
        $ui->apps = $o['apps'] ?? ['sms' => true];
        try {
            return [$ui->send_sms_participants(), null];
        } catch (\Throwable $e) {
            return [null, $e];
        }
    };
    $arm = static function (string $name, callable $body) use (&$failures): void {
        try {
            $body();
        } catch (\Throwable $e) {
            $failures[] = $name . ' — the arm itself threw ' . get_class($e) . ': ' . $e->getMessage();
        }
    };
    $err = static fn(string $key): array => ['status' => 'error', 'message' => "[lang:{$key}]"];
    $comment = static fn(string $text): array => [['add_single_comment', EVENT_ID, "SMS: {$text}", 'comment', OFFICER]];
    $didNotThrow = static function (string $name, ?\Throwable $t) use (&$failures): void {
        if ($t !== null) {
            $failures[] = $name . ' — threw ' . get_class($t) . ': ' . $t->getMessage();
        }
    };

    // ===== A1: nobody to send to ====================================================================
    foreach (['blank gateway' => [], 'configured gateway' => CONFIGURED] as $gwName => $gw) {
        $arm("A1 [{$gwName}]", function () use ($run, $same, $check, $err, $gw, $gwName, $didNotThrow) {
            [$r, $t] = $run(['participants' => [], 'gateway' => $gw]);
            $didNotThrow("A1 [{$gwName}]", $t);
            $same("A1 [{$gwName}] 0 participants: the «no participants» message", $r, $err('sms_event_no_participants'));
            $check("A1 [{$gwName}] the sms service is never created", !in_array('sms.sms', HdState::$constructed, true), implode(',', HdState::$constructed));
            $check("A1 [{$gwName}] the gateway config is not even read (participants are checked first)", !in_array('admin.soconfig', HdState::$constructed, true));
            $same("A1 [{$gwName}] nothing sent", HdState::$attempts, []);
            $same("A1 [{$gwName}] no read_single / update / comment", HdState::$boCalls, []);
        });
    }
    $arm('A1 read', function () use ($run, $same) {
        $run(['participants' => [P1]]);
        $same('A1 the participants are read for this event, as an event', HdState::$readParams, [[
            'results' => -1,
            'filters' => ['reservation_id' => EVENT_ID, 'reservation_type' => 'event'],
        ]]);
    });
    $arm('A1b', function () use ($run, $same, $check, $err, $didNotThrow) {
        [$r, $t] = $run(['participants' => ['', '   ', "\t", null]]);
        $didNotThrow('A1b', $t);
        $same('A1b only blank phones: the «no participants» message', $r, $err('sms_event_no_participants'));
        $check('A1b only blank phones: the sms service is never created', !in_array('sms.sms', HdState::$constructed, true));
        $same('A1b only blank phones: nothing sent', HdState::$attempts, []);
        $same('A1b only blank phones: no bo call', HdState::$boCalls, []);
    });
    $arm('A1c', function () use ($run, $same, $didNotThrow) {
        [$r, $t] = $run(['participants' => [P1, P1, ' ' . P1 . ' ']]);
        $didNotThrow('A1c', $t);
        $same('A1c the same number three times is sent to once', array_column(HdState::$attempts, 0), [P1]);
        $same('A1c ... and reported once', $r, ['status' => 'ok', 'message' => 'SMS sendt til: ' . P1]);
    });

    // ===== A2: no gateway ===========================================================================
    $arm('A2', function () use ($run, $same, $check, $err, $didNotThrow) {
        foreach (['empty config' => [], 'both modules blank' => ['gateway_module_send' => '', 'gateway_module_get' => '', 'other' => 'x']] as $what => $gw) {
            [$r, $t] = $run(['participants' => [P1], 'gateway' => $gw]);
            $didNotThrow("A2 [{$what}]", $t);
            $same("A2 [{$what}] one participant, no gateway: the «not configured» message", $r, $err('sms_not_configured'));
            $check("A2 [{$what}] the guard really ran (the config was read)", in_array('admin.soconfig', HdState::$constructed, true));
            $check("A2 [{$what}] the sms service is never created", !in_array('sms.sms', HdState::$constructed, true));
            $same("A2 [{$what}] nothing sent", HdState::$attempts, []);
            $same("A2 [{$what}] no bo call", HdState::$boCalls, []);
        }
        // control: a get-only and a send-only gateway are configured, the service is created and used
        foreach (['get only' => ['gateway_module_get' => 'gnokii'], 'send only' => ['gateway_module_send' => 'gnokii']] as $what => $gw) {
            [$r, $t] = $run(['participants' => [P1], 'gateway' => $gw]);
            $didNotThrow("A2 control [{$what}]", $t);
            $same("A2 control [{$what}]: configured, so the SMS is sent", $r, ['status' => 'ok', 'message' => 'SMS sendt til: ' . P1]);
            $check("A2 control [{$what}]: the sms service is created", in_array('sms.sms', HdState::$constructed, true));
        }
    });
    $arm('A2b', function () use ($run, $same, $check, $err, $didNotThrow) {
        foreach (['Exception' => fn() => new \RuntimeException('no such module'), 'Error' => fn() => new \Error('Class not found')] as $what => $make) {
            [$r, $t] = $run(['participants' => [P1], 'smsCreateThrows' => $make]);
            $didNotThrow("A2b [{$what}]", $t);
            $same("A2b [{$what}] the sms service cannot be created: the «not configured» message", $r, $err('sms_not_configured'));
            $same("A2b [{$what}] nothing sent", HdState::$attempts, []);
            $same("A2b [{$what}] no bo call", HdState::$boCalls, []);
            $check("A2b [{$what}] the reason is logged", count(HdState::$log) === 1, 'log entries: ' . count(HdState::$log));
        }
    });

    // ===== A3: every send fails ======================================================================
    $arm('A3', function () use ($run, $same, $check, $didNotThrow) {
        $failed = ['status' => 'error', 'message' => 'SMS feilet for: ' . P1];
        foreach (['empty gateway id' => ['empty', 0], 'RuntimeException' => ['throw-runtime', 1], 'TypeError (an Error)' => ['throw-type', 1]] as $what => [$beh, $aborts]) {
            [$r, $t] = $run(['participants' => [P1], 'behaviour' => [P1 => $beh]]);
            $didNotThrow("A3 [{$what}]", $t);
            $same("A3 [{$what}] status error, the failed number listed, nothing else", $r, $failed);
            $check("A3 [{$what}] no stray leading <br/>", !str_starts_with((string)($r['message'] ?? ''), '<br/>'));
            $same("A3 [{$what}] no comment, no read_single, no update", HdState::$boCalls, []);
            $same("A3 [{$what}] the open transaction is closed {$aborts} time(s)", Db::getInstance()->aborts, $aborts);
        }
        // the recipient AFTER a failing one is still sent (a leaked transaction would swallow it)
        foreach (['RuntimeException' => 'throw-runtime', 'TypeError' => 'throw-type'] as $what => $beh) {
            [$r, $t] = $run(['participants' => [P1, P2], 'behaviour' => [P1 => $beh]]);
            $didNotThrow("A3 after [{$what}]", $t);
            $msg = 'SMS sendt til: ' . P2 . '<br/>SMS feilet for: ' . P1;
            $same("A3 after [{$what}] the next recipient is sent and the failed one listed", $r, ['status' => 'ok', 'message' => $msg]);
            $same("A3 after [{$what}] the next recipient's send was really committed", HdState::$committed, [P2]);
            $same("A3 after [{$what}] one comment", HdState::$boCalls, [['add_single_comment', EVENT_ID, "SMS: {$msg}", 'comment', OFFICER]]);
        }
    });

    // ===== A4 / A5: mixed and all ok =================================================================
    $arm('A4', function () use ($run, $same, $comment, $didNotThrow) {
        [$r, $t] = $run(['participants' => [P1, P2, P3], 'behaviour' => [P2 => 'empty', P3 => 'throw-runtime']]);
        $didNotThrow('A4', $t);
        $msg = 'SMS sendt til: ' . P1 . '<br/>SMS feilet for: ' . P2 . ',' . P3;
        $same('A4 mixed: status ok, both lists', $r, ['status' => 'ok', 'message' => $msg]);
        $same('A4 mixed: exactly ONE bo call, the single comment (no read_single, no update)', HdState::$boCalls, $comment($msg));
    });
    $arm('A5', function () use ($run, $same, $check, $comment, $didNotThrow) {
        [$r, $t] = $run(['participants' => [P1, P2]]);
        $didNotThrow('A5', $t);
        $msg = 'SMS sendt til: ' . P1 . ',' . P2;
        $same('A5 all ok: status ok, one list', $r, ['status' => 'ok', 'message' => $msg]);
        $check('A5 all ok: no <br/>', !str_contains($msg, '<br/>') && !str_contains($r['message'], '<br/>'));
        $same('A5 all ok: exactly ONE bo call, the single comment', HdState::$boCalls, $comment($msg));
        $same('A5 each number gets the typed text, sent as the logged-in account', HdState::$attempts, [[P1, 'Hei', 7], [P2, 'Hei', 7]]);
    });

    // ===== A6: the comment cannot be written ==========================================================
    $arm('A6', function () use ($run, $same, $check, $didNotThrow) {
        foreach (['Exception' => fn() => new \RuntimeException('insert failed'), 'TypeError' => fn() => new \TypeError('wrong type')] as $what => $make) {
            [$r, $t] = $run(['participants' => [P1], 'commentThrows' => $make]);
            $didNotThrow("A6 [{$what}]", $t);
            $same("A6 [{$what}] the SMS went out: its result is still returned", $r, ['status' => 'ok', 'message' => 'SMS sendt til: ' . P1]);
            $check("A6 [{$what}] the failed comment is logged", count(HdState::$log) === 1, 'log entries: ' . count(HdState::$log));
        }
    });

    // ===== A7 / A8: pins that hold on master too ======================================================
    $arm('A7', function () use ($run, $same, $check) {
        [$r, $t] = $run(['participants' => [P1], 'apps' => []]);
        $same('A7 sms app disabled: false (PHP untouched, the JS turns it into the generic text)', $r, false);
        $same('A7 ... and the officer is told through the flash message', HdState::$cacheMessages, [['SMS er deaktivert', 'error']]);
        $same('A7 ... and nothing is created', HdState::$constructed, []);
    });
    $arm('A8', function () use ($run, $same) {
        $nothing = ['status' => 'error', 'message' => 'Nothing...'];
        [$r] = $run(['participants' => [P1], 'request' => ['id' => (string)EVENT_ID, 'sms_content' => 'Hei']]);
        $same('A8 no send_sms: «Nothing...»', $r, $nothing);
        [$r] = $run(['participants' => [P1], 'request' => ['id' => (string)EVENT_ID, 'send_sms' => '1', 'sms_content' => '']]);
        $same('A8 empty text: «Nothing...»', $r, $nothing);
        $same('A8 ... and nothing is created', HdState::$constructed, []);
    });

    // ===== A9: an event that does not load ===========================================================
    $arm('A9', function () use ($run, $same, $comment, $didNotThrow) {
        [$r, $t] = $run(['participants' => [P1], 'eventLoads' => false]);
        $didNotThrow('A9 unloaded event', $t);
        $same('A9 unloaded event: the send is still reported', $r, ['status' => 'ok', 'message' => 'SMS sendt til: ' . P1]);
        $same('A9 unloaded event: only the single comment, no read_single, no update', HdState::$boCalls, $comment('SMS sendt til: ' . P1));
    });

    // ===== A10: the numbers are escaped ================================================================
    $arm('A10', function () use ($run, $same, $check, $comment, $didNotThrow) {
        $bold = '<b>1</b>';
        $img = '<img src=x onerror=alert(1)>';
        [$r, $t] = $run(['participants' => [$bold, $img], 'behaviour' => [$img => 'empty']]);
        $didNotThrow('A10', $t);
        $msg = 'SMS sendt til: &lt;b&gt;1&lt;/b&gt;<br/>SMS feilet for: &lt;img src=x onerror=alert(1)&gt;';
        $same('A10 markup in a phone number comes back escaped, in both lists', $r, ['status' => 'ok', 'message' => $msg]);
        $check('A10 the only tag in the message is the <br/> the server adds', substr_count((string)$r['message'], '<') === 1, (string)$r['message']);
        $same('A10 the stored comment carries the escaped text too', HdState::$boCalls, $comment($msg));
        // the event history renders each comment with disable-output-escaping="yes" (event_edit.xsl): the same sink as .html()
        $stored = (string)(HdState::$boCalls[0][2] ?? '');
        $check('A10 the string handed to add_single_comment has the escaped number', str_contains($stored, '&lt;b&gt;1&lt;/b&gt;'), $stored);
        $check('A10 ... and not the raw tag', !str_contains($stored, '<b>') && !str_contains($stored, '<img'), $stored);
        $same('A10 the number is sent to as typed (the escape is on the message, not on the recipient)', array_column(HdState::$attempts, 0), [$bold, $img]);
        [$r] = $run(['participants' => [$img], 'behaviour' => [$img => 'empty']]);
        $same('A10 all failed, markup: escaped', $r, ['status' => 'error', 'message' => 'SMS feilet for: &lt;img src=x onerror=alert(1)&gt;']);
    });

    // ===== A11: the audit comment's author is the officer's NAME ========================================
    // add_single_comment() without an author writes the account id; event_edit.xsl prints `author` verbatim,
    // so the history would read «7» where it always read the officer's name.
    $arm('A11', function () use ($run, $same, $check, $didNotThrow) {
        foreach ([OFFICER, "Åse O'Brien-Lund"] as $name) {
            [$r, $t] = $run(['participants' => [P1], 'officer' => $name]);
            $didNotThrow("A11 [{$name}]", $t);
            $call = HdState::$boCalls[0] ?? [];
            $same("A11 [{$name}] one add_single_comment call, with a type and an author", count($call), 5);
            $same("A11 [{$name}] the author handed over is the officer's full name", $call[4] ?? null, $name);
            $check("A11 [{$name}] ... not the account id (7), as a number or as digits",
                ($call[4] ?? null) !== 7 && ($call[4] ?? null) !== '7' && !ctype_digit((string)($call[4] ?? '')), var_export($call[4] ?? null, true));
            $same("A11 [{$name}] the type is still the default 'comment'", $call[3] ?? null, 'comment');

            // the same string the old audit path stored: uievent::add_comment() puts current_account_fullname() in 'author'
            $ui = (new \ReflectionClass('booking_uievent'))->newInstanceWithoutConstructor();
            $event = ['comments' => []];
            (new \ReflectionMethod($ui, 'add_comment'))->invokeArgs($ui, [&$event, 'x']);
            $same("A11 [{$name}] ... the very author the old add_comment() stored", $call[4] ?? null, $event['comments'][0]['author'] ?? null);
        }
    });

    // ===== S: the REAL add_single_comment (bo -> so) on a recording Db ==================================
    $arm('S', function () use ($same, $check) {
        $newSo = static function (): array {
            $so = (new \ReflectionClass('booking_soevent'))->newInstanceWithoutConstructor();
            $db = new HdRecDb();
            (new \ReflectionProperty('booking_socommon', 'db'))->setValue($so, $db);
            (new \ReflectionProperty('booking_socommon', 'userSettings'))->setValue($so, ['account_id' => ACCOUNT_ID]);
            return [$so, $db];
        };
        $newBo = static function () use ($newSo): array {
            [$so, $db] = $newSo();
            $bo = (new \ReflectionClass('booking_boevent'))->newInstanceWithoutConstructor();
            $bo->so = $so;
            return [$bo, $db];
        };
        $sql = 'INSERT INTO bb_event_comment (event_id, time, author, comment, type) VALUES (?, ?, ?, ?, ?)';
        // The single valueset as it was before the author parameter existed: the account id, PARAM_INT. The
        // time is the clock, so it is shape-checked in $took and masked to «T» here.
        $before = static fn(string $comment, string $type, ?array $author = null): array => [[
            1 => ['value' => EVENT_ID, 'type' => PDO::PARAM_INT],
            2 => ['value' => 'T', 'type' => PDO::PARAM_STR],
            3 => $author ?? ['value' => ACCOUNT_ID, 'type' => PDO::PARAM_INT],
            4 => ['value' => $comment, 'type' => PDO::PARAM_STR],
            5 => ['value' => $type, 'type' => PDO::PARAM_STR],
        ]];
        $took = static function (string $what, HdRecDb $db) use ($same, $check, $sql): array {
            $same("S [{$what}] exactly one insert", count($db->inserts), 1);
            [$seenSql, $valueset] = $db->inserts[0] ?? ['', [[]]];
            $same("S [{$what}] the SQL is unchanged", $seenSql, $sql);
            $time = (string)($valueset[0][2]['value'] ?? '');
            $check("S [{$what}] the time is a UTC «Y-m-d H:i»", preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $time) === 1, $time);
            if (isset($valueset[0][2])) {
                $valueset[0][2]['value'] = 'T';
            }
            return $valueset;
        };
        $str = static fn(string $value): array => ['value' => $value, 'type' => PDO::PARAM_STR];

        // the author omitted: byte for byte what the async callers have always written
        [$so, $db] = $newSo();
        $so->add_single_comment(EVENT_ID, 'c');
        $same('S so, no author: the account id as PARAM_INT, nothing else changed', $took('so, no author', $db), $before('c', 'comment'));
        [$so, $db] = $newSo();
        $so->add_single_comment(EVENT_ID, 'c', 'system');
        $same('S so, a type and no author: the same, with the type', $took('so, type', $db), $before('c', 'system'));
        [$so, $db] = $newSo();
        $so->add_single_comment(EVENT_ID, 'c', 'comment', null);
        $same('S so, author null: the same as omitted', $took('so, null', $db), $before('c', 'comment'));

        // the author given: bound as a string, the rest unchanged
        [$so, $db] = $newSo();
        $so->add_single_comment(EVENT_ID, 'c', 'comment', OFFICER);
        $same('S so, an author: PARAM_STR with the name, nothing else changed', $took('so, author', $db), $before('c', 'comment', $str(OFFICER)));
        [$so, $db] = $newSo();
        $so->add_single_comment(EVENT_ID, 'c', 'system', "Åse O'Brien-Lund");
        $same("S so, a name with an apostrophe is bound raw (a bound value is not quoted again)", $took('so, apostrophe', $db), $before('c', 'system', $str("Åse O'Brien-Lund")));
        [$so, $db] = $newSo();
        $so->add_single_comment(EVENT_ID, 'c', 'comment', '');
        $same('S so, an empty name IS given: PARAM_STR «», not the account id', $took('so, empty', $db), $before('c', 'comment', $str('')));

        // through the real bo
        [$bo, $db] = $newBo();
        $bo->add_single_comment(EVENT_ID, 'c');
        $same('S bo, no author: reaches the so as no author (the account id)', $took('bo, no author', $db), $before('c', 'comment'));
        [$bo, $db] = $newBo();
        $bo->add_single_comment(EVENT_ID, 'c', 'comment', OFFICER);
        $same('S bo, an author: reaches the so', $took('bo, author', $db), $before('c', 'comment', $str(OFFICER)));

        // control: the recording Db is reached by the call (a call that stops before the insert records nothing)
        [$so, $db] = $newSo();
        $check('S control: nothing is recorded before a call', $db->inserts === []);
    });

    // ===== C: who calls ->add_single_comment( and with how many arguments ===============================
    $arm('C', function () use ($root, $same, $check) {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS),
            static fn(\SplFileInfo $f) => !in_array($f->getFilename(), ['node_modules', 'vendor', '.next'], true)
        ));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $src = (string)file_get_contents($file->getPathname());
            if (!str_contains($src, 'add_single_comment')) {
                continue;
            }
            preg_match_all('/->add_single_comment\(/', $src, $hits, PREG_OFFSET_CAPTURE);
            foreach ($hits[0] as [$hit, $at]) {
                $found[substr($file->getPathname(), strlen($root) + 1)][] = hd_count_args($src, $at + strlen($hit));
            }
        }
        ksort($found);
        // control: the so file holds the DEFINITION (the name is in it) and the scan walked it, but a definition is not a call
        $soPath = 'src/modules/booking/inc/class.soevent.inc.php';
        $check('C control: the so file defines add_single_comment and is not counted as a caller',
            str_contains((string)file_get_contents($root . '/' . $soPath), 'function add_single_comment(') && !isset($found[$soPath]));
        $same('C the callers of add_single_comment: the 4 access-request reminders pass 2 arguments (the default author), the bo forwards 4, this button passes 4', $found, [
            'src/modules/booking/inc/class.async_task_send_access_request.inc.php' => [2, 2, 2, 2],
            'src/modules/booking/inc/class.boevent.inc.php' => [4],
            'src/modules/booking/inc/class.uievent.inc.php' => [4],
        ]);
    });

    // ===== H / P / G / S / X: the box is judged UP FRONT with the button's own rule (task 36828) ==========
    // The oracle is written here, from the rule in the brief, and shares no code with the tree under test.
    $oracle = static fn(array $phones): array => array_values(array_unique(array_filter(
        array_map(static fn($p) => trim((string)$p), $phones),
        static fn($p) => $p !== ''
    )));
    $PHONE_SETS = [
        'no participants' => [],
        'only blank, whitespace and null phones' => ['', '   ', "\t", null],
        'one number' => [P1],
        'one padded number' => ['  ' . P1 . '  '],
        'a number, its twin and its padded twin' => [P1, P1, ' ' . P1 . ' '],
        'a blank and a number' => ['', P1],
        'the strings of the manual test (non-dialable)' => ['T36828-FAKE-1', '  T36828-FAKE-2  '],
        'two numbers' => [P1, P2],
    ];
    $invoke = static function (array $apps, string $method, array $o = []) {
        HdState::reset();
        HdState::$participants = $o['participants'] ?? [];
        HdState::$readThrows = $o['readThrows'] ?? null;
        $ui = (new \ReflectionClass('booking_uievent'))->newInstanceWithoutConstructor();
        $ui->bo = new HdBo();
        $ui->apps = $apps;
        try {
            return [(new \ReflectionMethod('booking_uievent', $method))->invoke($ui, EVENT_ID), null];
        } catch (\Throwable $e) {
            return [null, $e];
        }
    };
    $readOnce = [['results' => -1, 'filters' => ['reservation_id' => EVENT_ID, 'reservation_type' => 'event']]];

    $arm('H', function () use ($invoke, $same, $check, $oracle, $PHONE_SETS, $readOnce, $didNotThrow) {
        foreach ($PHONE_SETS as $name => $phones) {
            [$got, $t] = $invoke(['sms' => true], 'get_event_sms_recipients', ['participants' => $phones]);
            $didNotThrow("H [{$name}]", $t);
            $same("H [{$name}] the recipients are the distinct, trimmed, non-blank numbers", is_array($got) ? array_values($got) : $got, $oracle($phones));
            $same("H [{$name}] the participants are read for this event, as an event, all of them", HdState::$readParams, $readOnce);
        }
    });

    $arm('P', function () use ($invoke, $same, $check, $oracle, $PHONE_SETS, $readOnce, $didNotThrow) {
        $seen = [];
        foreach ($PHONE_SETS as $name => $phones) {
            [$flag, $t] = $invoke(['sms' => true], 'sms_has_no_recipients_for_page', ['participants' => $phones]);
            $didNotThrow("P [{$name}]", $t);
            $same("P [{$name}] disabled up front exactly when nobody can be texted", $flag, $oracle($phones) === []);
            $same("P [{$name}] it reads the same participants the button reads", HdState::$readParams, $readOnce);
            $seen[] = $flag;
        }
        $check('P control: the matrix has both answers (a flag that is always the same would pass every row above)', in_array(true, $seen, true) && in_array(false, $seen, true));

        // SMS off: the page is as it always was. Nothing is read, nothing is even created.
        foreach ([[], ['sms' => false], ['sms' => '']] as $i => $apps) {
            [$flag, $t] = $invoke($apps, 'sms_has_no_recipients_for_page', ['participants' => []]);
            $didNotThrow("P sms off #{$i}", $t);
            $same("P sms off #{$i}: not disabled, although the event has nobody", $flag, false);
            $same("P sms off #{$i}: no participant object is created", HdState::$constructed, []);
            $same("P sms off #{$i}: no participant read", HdState::$readParams, []);
        }

        // The read throws: the page must still render, with the box ENABLED, and the failure is logged.
        foreach (['Exception' => fn() => new \RuntimeException('db gone'), 'TypeError' => fn() => new \TypeError('wrong type')] as $what => $make) {
            [$flag, $t] = $invoke(['sms' => true], 'sms_has_no_recipients_for_page', ['participants' => [], 'readThrows' => $make]);
            $didNotThrow("P read throws [{$what}]", $t);
            $same("P read throws [{$what}]: enabled (the guard in the button still decides)", $flag, false);
            $same("P read throws [{$what}]: logged once", count(HdState::$log), 1);
        }
    });

    // The button keeps its own behaviour on a throwing read: no new catch there (the page's fallback is the page's only).
    $arm('P send', function () use ($run, $check) {
        [$r, $t] = $run(['participants' => [P1], 'readThrows' => fn() => new \RuntimeException('db gone')]);
        $check('P send: a throwing read still propagates out of send_sms_participants (unchanged: the fallback is the page\'s)', $t instanceof \RuntimeException, 'got ' . ($t ? get_class($t) : 'a normal return'));
    });

    // ===== I: the event id that reaches send_sms_participants (task 36861, Opus Q2 on 5b025b196) ============
    // The helper is typed `int $event_id`. Sanitizer::get_var('id', 'int') is null for a missing id, 0 for text and
    // an ARRAY for id[]=...; null and an array both throw a TypeError at the helper. A bare (int) cast is no cure:
    // (int)array('5','6') is 1, so id[]=a&id[]=b would read EVENT 1's participants and text them. Only a positive
    // int may reach the helper; anything else has nobody to text, and nothing is read at all.
    // Each row is [what the request carries, what the REAL Sanitizer hands the method]: asserted in I1, not assumed.
    $BAD_IDS = [
        'no id at all'                => [NO_ID, null],
        'id=abc'                      => ['abc', 0],
        'id= (empty)'                 => ['', 0],
        'id=%20 (blank)'              => ['  ', 0],
        'id=1.5'                      => ['1.5', 0],
        'id=5abc'                     => ['5abc', 0],
        'id=0'                        => ['0', 0],
        'id=-3'                       => ['-3', -3],
        'id[]=5&id[]=6'               => [['5', '6'], [5, 6]],
        'id[]=a&id[]=b'               => [['a', 'b'], [0, 0]],
        'id[]=1 (an array holding 1)' => [['1'], [1]],
        'id[a][b]=1 (nested)'         => [['a' => ['b' => '1']], ['a' => ['b' => 1]]],
    ];
    $GOOD_IDS = [
        'id=5'            => ['5', 5],
        'id=84227'        => [(string)EVENT_ID, EVENT_ID],
        'id=%207%20'      => [' 7 ', 7],
    ];

    $arm('I1', function () use ($BAD_IDS, $GOOD_IDS, $same) {
        foreach ($BAD_IDS + $GOOD_IDS as $name => [$carried, $sanitized]) {
            HdState::reset();
            $_GET = $_POST = [];
            $_REQUEST = $carried === NO_ID ? [] : ['id' => $carried];
            $same("I1 [{$name}] Sanitizer::get_var('id', 'int') hands the method", Sanitizer::get_var('id', 'int'), $sanitized);
        }
    });

    $arm('I2', function () use ($run, $same, $err, $BAD_IDS, $didNotThrow) {
        foreach ($BAD_IDS as $name => [$carried]) {
            // participants ARE registered, on purpose: a read of ANY event (0, 1, ...) would find and text them
            [$r, $t] = $run(['participants' => [P1, P2], 'request' => hd_id_request($carried)]);
            $didNotThrow("I2 [{$name}]", $t);
            $same("I2 [{$name}] answers the «no participants» message", $r, $err('sms_event_no_participants'));
            $same("I2 [{$name}] the participants of NO event are read (not event 0, not event 1)", HdState::$readParams, []);
            $same("I2 [{$name}] nothing is created at all (no participant object, no gateway config, no sms service)", HdState::$constructed, []);
            $same("I2 [{$name}] nothing is sent", HdState::$attempts, []);
            $same("I2 [{$name}] no comment, no read_single, no update", HdState::$boCalls, []);
        }
    });

    $arm('I3', function () use ($run, $same, $err, $GOOD_IDS, $didNotThrow) {
        foreach ($GOOD_IDS as $name => [$carried, $int]) {
            [$r, $t] = $run(['participants' => [P1], 'request' => hd_id_request($carried)]);
            $didNotThrow("I3 [{$name}]", $t);
            $same("I3 [{$name}] control: a valid id is sent as before", $r, ['status' => 'ok', 'message' => 'SMS sendt til: ' . P1]);
            $same("I3 [{$name}] ... the participants of exactly that event are read, with that int", HdState::$readParams, [[
                'results' => -1,
                'filters' => ['reservation_id' => $int, 'reservation_type' => 'event'],
            ]]);
            $same("I3 [{$name}] ... and the comment goes on that event", HdState::$boCalls, [['add_single_comment', $int, 'SMS: SMS sendt til: ' . P1, 'comment', OFFICER]]);
        }
        // the guard sits inside the send_sms branch: without send_sms a missing id still gets «Nothing...», untouched
        [$r, $t] = $run(['participants' => [P1], 'request' => ['sms_content' => 'Hei']]);
        $didNotThrow('I3 no id, no send_sms', $t);
        $same('I3 no id and no send_sms: «Nothing...», as before', $r, ['status' => 'error', 'message' => 'Nothing...']);
        $same('I3 ... and nothing is created', HdState::$constructed, []);
        // positive control for the zero reads in I2: a valid id with nobody registered gives the same message, but its read IS recorded
        [$r, $t] = $run(['participants' => [], 'request' => hd_id_request((string)EVENT_ID)]);
        $didNotThrow('I3 control', $t);
        $same('I3 control: a valid id with nobody registered answers the same message, and the read IS seen (so a zero in I2 is a zero)',
            [$r, count(HdState::$readParams)], [$err('sms_event_no_participants'), 1]);
    });

    $arm('G', function () use ($run, $invoke, $same, $check, $oracle, $err, $PHONE_SETS, $didNotThrow) {
        foreach ($PHONE_SETS as $name => $phones) {
            [$flag] = $invoke(['sms' => true], 'sms_has_no_recipients_for_page', ['participants' => $phones]);
            [$r, $t] = $run(['participants' => $phones]);
            $didNotThrow("G [{$name}]", $t);
            $answeredNobody = $r === $err('sms_event_no_participants');
            $same("G [{$name}] the page disables the box exactly when the button answers «no participants»", $flag, $answeredNobody);
            if (!$answeredNobody) {
                $same("G [{$name}] ... and otherwise the button sends to exactly the numbers the rule gives", array_column(HdState::$attempts, 0), $oracle($phones));
            }
        }
    });

    $arm('S', function () use ($root, $same, $check) {
        $src = (string)file_get_contents($root . '/src/modules/booking/inc/class.uievent.inc.php');
        $between = static function (string $from, string $to) use ($src): string {
            $a = strpos($src, $from);
            $b = $a === false ? false : strpos($src, $to, $a + strlen($from));
            return ($a === false || $b === false) ? '' : substr($src, $a, $b - $a);
        };
        $helper = $between('function get_event_sms_recipients(', 'function sms_has_no_recipients_for_page(');
        $page = $between('function sms_has_no_recipients_for_page(', 'public function send_sms_participants()');
        $send = $between('public function send_sms_participants()', 'public function edit()');
        $edit = $between('public function edit()', 'public function delete()');
        foreach (['helper' => $helper, 'page method' => $page, 'send_sms_participants' => $send, 'edit' => $edit] as $what => $body) {
            $check("S the {$what} was found in class.uievent.inc.php (the cuts below are not empty)", strlen($body) > 100, 'length ' . strlen($body));
        }
        // the rule and the participant read live in ONE place
        $same('S the participant object is created exactly once in the whole class (in the helper)', substr_count(strtolower($src), "createobject('booking.soparticipant')"), 1);
        $check('S ... and that one is in the helper', substr_count(strtolower($helper), "createobject('booking.soparticipant')") === 1);
        $same('S the trim of a participant phone exists exactly once in the whole class', substr_count($src, "trim((string)\$participant['phone'])"), 1);
        $same('S ... and that one is in the helper', substr_count($helper, "trim((string)\$participant['phone'])"), 1);
        // both callers go through it and carry no rule of their own
        $same('S send_sms_participants asks the helper exactly once', substr_count($send, '$this->get_event_sms_recipients('), 1);
        $same('S ... and only for a positive int: the whole guarded line, text for text (the behaviour is I2)',
            substr_count($send, '$final_recipients = (is_int($id) && $id > 0) ? $this->get_event_sms_recipients($id) : array();'), 1);
        $check('S ... and the id is never cast (an (int) cast turns an array into event 1)', !str_contains($send, '(int)$id') && !str_contains($send, '(int) $id'));
        $same('S the page method asks the helper, once', substr_count($page, '$this->get_event_sms_recipients('), 1);
        // (edit() has unrelated trim()/array_unique()/['phone'] in its mail code: only the participant read and a
        // participant's phone are looked for there)
        foreach (['send_sms_participants' => [$send, ['soparticipant', 'trim(', 'array_unique(', "['phone']"]],
                  'page method' => [$page, ['soparticipant', 'trim(', 'array_unique(', "['phone']"]],
                  'edit' => [$edit, ['soparticipant', '$participant']]] as $what => [$body, $pieces]) {
            foreach ($pieces as $piece) {
                $check("S the {$what} holds no copy of the rule («{$piece}»)", !str_contains($body, $piece));
            }
        }
        // edit() hands the answer to the template, under the name the template tests
        // The id and the direction are pinned whole (Opus Q1 on 5b025b196: `? 0 : 1` and `(int)$event['application_id']`
        // both survived a scan that stopped at the variable name and at the opening parenthesis).
        $same('S edit() asks the page method, once', substr_count($edit, '$this->sms_has_no_recipients_for_page('), 1);
        $same('S ... for the id edit() loaded the event with, as an int, and keeps the answer as it is (not negated)',
            substr_count($edit, '$sms_no_recipients = $this->sms_has_no_recipients_for_page((int)$id);'), 1);
        $check('S edit() passes it to the event_edit template as «sms_no_recipients» => $sms_no_recipients ? 1 : 0 (1 = nobody to text)',
            (bool)preg_match("/render_template_xsl\\('event_edit',\\s*array\\((?:(?!\\n\\t\\t\\)\\);).)*'sms_no_recipients'\\s*=>\\s*\\\$sms_no_recipients\\s*\\?\\s*1\\s*:\\s*0\\b/s", $edit));
        // control: the pieces searched for ARE found where they must be, so a zero above is a zero
        $check('S control: the helper holds the read filter and the trim (the cuts are the right ones)',
            str_contains($helper, "'reservation_type' => 'event'") && str_contains($helper, 'trim('));
        $check('S control: send_sms_participants still holds its own guard (the server check is kept)', str_contains($send, 'if (!$final_recipients)') && str_contains($send, "lang('sms_event_no_participants')"));
    });

    $arm('X', function () use ($root, $same, $check) {
        $need = $root . '/src/modules/phpgwapi/inc/class.xmlhelper.inc.php';
        if (!is_file($need)) {
            throw new \RuntimeException('the real toXML is not readable: ' . $need);
        }
        require_once $need;
        $xslSource = (string)file_get_contents($root . '/src/modules/booking/templates/base/event_edit.xsl');
        foreach (['<label for="field_sms_content">', 'onClick="SendSms();"'] as $anchor) {
            $check("X the template holds «{$anchor}» exactly once", substr_count($xslSource, $anchor) === 1);
        }
        $labelAt = (int)strpos($xslSource, '<label for="field_sms_content">');
        $from = strrpos(substr($xslSource, 0, $labelAt), '<div class="pure-control-group">');
        $btnAt = (int)strpos($xslSource, 'onClick="SendSms();"');
        $btnEnd = (int)strpos($xslSource, '</button>', $btnAt) + strlen('</button>');
        $to = strpos($xslSource, '</div>', $btnEnd);
        if ($from === false || $to === false) {
            throw new \RuntimeException('the SMS block could not be cut out of event_edit.xsl');
        }
        $fragment = substr($xslSource, $from, $to + strlen('</div>') - $from);
        foreach (['id="field_sms_content"', 'id="sms_receipt"', 'SendSms();'] as $mustHave) {
            $check("X the cut block holds «{$mustHave}» (it is the SMS block)", str_contains($fragment, $mustHave));
        }
        $check('X the cut block holds only the SMS box (no other form field came along)', substr_count($fragment, '<textarea') === 1 && substr_count($fragment, '<input') === 0 && substr_count($fragment, '<button') === 1);

        $doc = new \DOMDocument();
        $sheet = '<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform" xmlns:php="http://php.net/xsl">'
            . '<xsl:output method="html" encoding="UTF-8" indent="yes"/>'
            . '<xsl:template match="/"><xsl:apply-templates select="PHPGW/data"/></xsl:template>'
            . '<xsl:template match="data">' . $fragment . '</xsl:template></xsl:stylesheet>';
        if (!@$doc->loadXML($sheet)) {
            throw new \RuntimeException('the stylesheet around the cut SMS block is not well-formed XML');
        }
        $proc = new \XSLTProcessor();
        $proc->registerPHPFunctions(['lang']);
        $proc->importStylesheet($doc);

        /** The block the template gives for this data: [html, textarea, button, receipt]. */
        $render = static function (?int $flag) use ($proc): array {
            $data = ['event' => ['id' => EVENT_ID]];
            if ($flag !== null) {
                $data['sms_no_recipients'] = $flag;
            }
            $in = new \DOMDocument();
            $in->loadXML(\phpgwapi_xmlhelper::toXML(['data' => $data], 'PHPGW'));
            $html = (string)$proc->transformToXml($in);
            $out = new \DOMDocument();
            $prev = libxml_use_internal_errors(true);
            $out->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            $xp = new \DOMXPath($out);
            return [$html, $xp->query('//textarea[@id="field_sms_content"]')->item(0), $xp->query('//button')->item(0), $xp->query('//*[@id="sms_receipt"]')->item(0), $xp];
        };
        $message = '[lang:sms_event_no_participants]';

        [$html, $ta, $btn, $rcpt, $xp] = $render(1);
        $check('X flag 1: the textarea exists and is disabled', $ta !== null && $ta->hasAttribute('disabled'));
        $check('X flag 1: the send button exists and is disabled', $btn !== null && $btn->hasAttribute('disabled'));
        $check('X flag 1: the button still runs SendSms() (only disabled, nothing else changed)', $btn !== null && $btn->getAttribute('onclick') === 'SendSms();');
        $check('X flag 1: #sms_receipt holds the existing message, in the error class event.js uses',
            $rcpt !== null && $xp->query('./div[@class="error"]', $rcpt)->length === 1 && trim($rcpt->textContent) === $message, $rcpt ? trim($rcpt->textContent) : 'no receipt');
        $same('X flag 1: the message is shown once on the whole block', substr_count($html, $message), 1);
        $check('X flag 1: the box keeps its name (a disabled field is simply not posted)', $ta !== null && $ta->getAttribute('name') === 'sms_content');

        foreach (['0' => 0, 'absent' => null] as $name => $flag) {
            [$html0, $ta0, $btn0, $rcpt0, $xp0] = $render($flag);
            $check("X flag {$name}: the textarea is enabled", $ta0 !== null && !$ta0->hasAttribute('disabled'));
            $check("X flag {$name}: the send button is enabled", $btn0 !== null && !$btn0->hasAttribute('disabled'));
            $check("X flag {$name}: #sms_receipt is empty", $rcpt0 !== null && $xp0->query('.//*', $rcpt0)->length === 0 && trim($rcpt0->textContent) === '');
            $check("X flag {$name}: the message is nowhere", !str_contains($html0, $message));
            $check("X flag {$name}: the button still runs SendSms()", $btn0 !== null && $btn0->getAttribute('onclick') === 'SendSms();');
        }
        // The message is printed escaped, as a lang text always was (the lang text itself is not trusted to be plain).
        HdState::$lang['sms_event_no_participants'] = '<b onmouseover="x()">no</b> & "quoted"';
        [$htmlE, $taE, $btnE, $rcptE, $xpE] = $render(1);
        HdState::$lang = [];
        $check('X escape: a lang text with markup is shown as text, not as elements',
            $rcptE !== null && $xpE->query('.//b', $rcptE)->length === 0 && trim($rcptE->textContent) === '<b onmouseover="x()">no</b> & "quoted"',
            $rcptE ? trim($rcptE->textContent) : 'no receipt');
        $check('X control: ... the same text IS seen as markup when printed raw (the check can fail)',
            (new \DOMXPath((function () { $d = new \DOMDocument(); @$d->loadHTML('<body><div><b onmouseover="x()">no</b></div></body>'); return $d; })()))->query('//b')->length === 1);
        $check('X control: flag 1 and flag 0 give different blocks (the instrument can tell them apart)', $render(1)[0] !== $render(0)[0]);
        $check('X control: flag 0 and an absent flag give the same block (a page that passes nothing is as before)', $render(0)[0] === $render(null)[0]);
    });

    // ===== controls: the instrument can fail ============================================================
    $arm('controls', function () use ($run, $same, $check) {
        // the stub models a leaked transaction: while open, an ok-looking send is not committed
        HdState::reset();
        Db::getInstance()->open = true;
        $res = (new HdSms())->websend2pv(7, P1, 'x');
        $check('control: a send inside a leaked transaction is reported uncommitted', empty($res[0][0]));
        // the stub bo throws on an array with no id, like authorize_write
        $thrown = false;
        try {
            (new HdBo())->update(['comments' => []]);
        } catch (\InvalidArgumentException $e) {
            $thrown = true;
        }
        $check('control: bo->update throws on an id-less array (the unloaded-event crash is reachable)', $thrown);
        // the real Sanitizer reads $_REQUEST, not $_POST
        HdState::reset();
        $_REQUEST = [];
        $_POST = ['id' => '1', 'send_sms' => '1', 'sms_content' => 'Hei'];
        $check('control: a POST-only fixture is NOT seen by Sanitizer (the reason input goes in $_REQUEST)', !Sanitizer::get_var('send_sms', 'bool'));
        $_REQUEST = ['send_sms' => '1'];
        $check('control: the same flag in $_REQUEST IS seen', Sanitizer::get_var('send_sms', 'bool') === true);
        // the guard text was really lifted from the tree's uicommon
        $check('control: the parent carries the lifted sms_is_configured()', method_exists('booking_uicommon', 'sms_is_configured'));
        // the argument counter of arm C: nesting, quotes holding commas and parens, a call spread over lines
        $src = "->f(\$a, g(1, 2), \"x,y)\", 'p(q', [1, 2]); ->h(); ->k(\n  \$a,\n  \$b\n);";
        $count = static fn(string $call): int => hd_count_args($src, strpos($src, $call) + strlen($call));
        $same('control: argument counter, nested call + quoted commas and parens + array', $count('->f('), 5);
        $same('control: argument counter, no arguments', $count('->h('), 0);
        $same('control: argument counter, a call over several lines', $count('->k('), 2);
        // a call that must succeed does succeed, so the arms above are not all red or all green by construction
        [$r, $t] = $run(['participants' => [P1]]);
        $same('control: the plain happy path is green', [$t, $r['status'] ?? null], [null, 'ok']);
    });

    // ===================================================================================================
    $total = $passed + count($failures);
    if ($failures) {
        foreach ($failures as $f) {
            echo 'FAIL ', $f, "\n";
        }
        echo 'Event SMS harness: FAIL ', count($failures), " of {$total} checks\n";
        exit(1);
    }
    echo "Event SMS harness: PASS {$total} checks on PHP ", PHP_VERSION, "\n";
}
