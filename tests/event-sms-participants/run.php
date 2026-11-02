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
 *   A7/A8 pins that are GREEN on master too: sms app disabled gives false; no send_sms gives «Nothing...».
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

    // --- the state every stub records into --------------------------------------------------------------
    final class HdState
    {
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

        public static function reset(): void
        {
            self::$constructed = self::$log = self::$cacheMessages = self::$locationLookups = [];
            self::$boCalls = self::$attempts = self::$committed = self::$readParams = [];
            self::$participants = self::$gateway = self::$behaviour = [];
            self::$smsCreateThrows = self::$commentThrows = null;
            self::$eventLoads = true;
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
        return "[lang:{$key}]";
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
        public function add_single_comment($event_id, $comment, $type = 'comment')
        {
            HdState::$boCalls[] = ['add_single_comment', $event_id, $comment, $type];
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
            public static function current_account_fullname() { return "Harness Officer"; }
            ' . $guardSource . '
        }');

    if (!is_file($root . '/src/helpers/Sanitizer.php') || !is_file($root . '/src/modules/booking/inc/class.uievent.inc.php')) {
        $fatal('Sanitizer.php or class.uievent.inc.php is missing under ' . $root);
    }
    require_once $root . '/src/helpers/Sanitizer.php';
    require_once $root . '/src/modules/booking/inc/class.uievent.inc.php';

    // --- driving it ------------------------------------------------------------------------------------
    const EVENT_ID = 84227;
    const P1 = '91000001';
    const P2 = '91000002';
    const P3 = '91000003';
    const CONFIGURED = ['gateway_module_send' => 'gnokii'];

    /**
     * One request through the REAL method. Options: participants (phones), gateway (config 'common'),
     * behaviour (phone => ok|empty|throw-runtime|throw-type), smsCreateThrows, commentThrows (closures
     * giving a fresh Throwable), eventLoads, apps, request (replaces the default send-sms request).
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
    $comment = static fn(string $text): array => [['add_single_comment', EVENT_ID, "SMS: {$text}", 'comment']];
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
            $same("A3 after [{$what}] one comment", HdState::$boCalls, [['add_single_comment', EVENT_ID, "SMS: {$msg}", 'comment']]);
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
        $same('A10 the number is sent to as typed (the escape is on the message, not on the recipient)', array_column(HdState::$attempts, 0), [$bold, $img]);
        [$r] = $run(['participants' => [$img], 'behaviour' => [$img => 'empty']]);
        $same('A10 all failed, markup: escaped', $r, ['status' => 'error', 'message' => 'SMS feilet for: &lt;img src=x onerror=alert(1)&gt;']);
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
