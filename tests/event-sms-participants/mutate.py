#!/usr/bin/env python3
"""
Mutation check for the event-participant-SMS harnesses (run.php and run_js.mjs, GH #1327, task 36097).

Same idea as tests/hospitality-deadline/mutate_citizen.py: one deliberate defect at a time, applied to a
TEMP COPY of the files the harnesses read (never to the tree), and the matching harness must go red for
each. The baseline on the same copy must be green first, and every replacement must match exactly once.
A mutation counts as caught only when the harness exits 1 with a FAIL line: any other exit (a crash, a
fatal error) is reported as CRASH and fails the run, because a harness that dies has not looked at anything.

Usage:  python3 tests/event-sms-participants/mutate.py
        (PHP_BIN and NODE_BIN override the binaries)
"""
import os
import re
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
PHP = os.environ.get("PHP_BIN", "php")
NODE = os.environ.get("NODE_BIN", "node")

# Everything the two harnesses read from HD_ROOT.
FILES = [
    "src/helpers/Sanitizer.php",
    "src/modules/booking/inc/class.uicommon.inc.php",
    "src/modules/booking/inc/class.uievent.inc.php",
    "src/modules/booking/inc/class.soevent.inc.php",
    "src/modules/booking/inc/class.boevent.inc.php",
    "src/modules/booking/inc/class.async_task_send_access_request.inc.php",
    "src/modules/booking/js/base/event.js",
    "src/modules/booking/templates/base/event_edit.xsl",
    "src/modules/booking/setup/phpgw_no.lang",
    "src/modules/booking/setup/phpgw_nn.lang",
    "src/modules/booking/setup/phpgw_en.lang",
]

U = "src/modules/booking/inc/class.uievent.inc.php"
SO = "src/modules/booking/inc/class.soevent.inc.php"
BO = "src/modules/booking/inc/class.boevent.inc.php"
ASYNC = "src/modules/booking/inc/class.async_task_send_access_request.inc.php"
G = "src/modules/booking/inc/class.uicommon.inc.php"

# The one call the button makes; the author mutations below rewrite it.
CALL = "$this->bo->add_single_comment($id, \"SMS: $message\", 'comment', $this->current_account_fullname());"
E = "src/modules/booking/js/base/event.js"
X = "src/modules/booking/templates/base/event_edit.xsl"
LANG = "src/modules/booking/setup/phpgw_{}.lang"

# (id, harness, file, description, old, new); old None = ADD the file `file` with the content `new`
MUTATIONS = [
    # --- nobody to send to ---------------------------------------------------------------------------
    ("Mp1", "php", U, "no early return when there is nobody to send to",
     "\t\t\tif (!$final_recipients)\n", "\t\t\tif (false)\n"),
    ("Mp2", "php", U, "the early return comes AFTER CreateObject('sms.sms')",
     "\t\t\tif (!$final_recipients)\n", "\t\t\tCreateObject('sms.sms');\n\t\t\tif (!$final_recipients)\n"),
    ("Mp3", "php", U, "the participants check yields to the gateway check (order swapped)",
     "\t\t\tif (!$final_recipients)\n", "\t\t\tif (!$final_recipients && $this->sms_is_configured())\n"),
    ("Mp4", "php", U, "the early return has the wrong lang key",
     "lang('sms_event_no_participants')", "lang('no records found.')"),
    ("Mp5", "php", U, "the early return says status ok",
     "\t\t\t\t\t'status' => 'error',\n\t\t\t\t\t'message' => lang('sms_event_no_participants')",
     "\t\t\t\t\t'status' => 'ok',\n\t\t\t\t\t'message' => lang('sms_event_no_participants')"),
    ("Mp6", "php", U, "no de-duplication of the numbers",
     "$final_recipients = array_unique($sms_recipients);", "$final_recipients = $sms_recipients;"),
    ("Mp7", "php", U, "blank phones are kept",
     "if ($phone !== '')", "if (true)"),
    ("Mp8", "php", U, "the numbers are not trimmed",
     "$phone = trim((string)$participant['phone']);", "$phone = (string)$participant['phone'];"),
    # --- the gateway -----------------------------------------------------------------------------------
    ("Mp9", "php", U, "no gateway guard",
     "if (!$this->sms_is_configured())", "if (false)"),
    ("Mp10", "php", U, "the sms service is created BEFORE the gateway is checked",
     "\t\t\tif (!$this->sms_is_configured())\n", "\t\t\tCreateObject('sms.sms');\n\t\t\tif (!$this->sms_is_configured())\n"),
    ("Mp11", "php", U, "a throw while creating the sms service is no longer contained",
     "\t\t\tcatch (\\Throwable $e)\n\t\t\t{\n\t\t\t\t(new Log())->error(array(\n\t\t\t\t\t'text'\t => \"SMS service could not be created",
     "\t\t\tcatch (\\InvalidArgumentException $e)\n\t\t\t{\n\t\t\t\t(new Log())->error(array(\n\t\t\t\t\t'text'\t => \"SMS service could not be created"),
    ("Mp12", "php", U, "a failed service creation is not logged",
     "(new Log())->error(array(\n\t\t\t\t\t'text'\t => \"SMS service could not be created",
     "(array(\n\t\t\t\t\t'text'\t => \"SMS service could not be created"),
    ("Mg1", "php", G, "the (lifted) guard needs BOTH gateway modules",
     "return !empty($common['gateway_module_send']) || !empty($common['gateway_module_get']);",
     "return !empty($common['gateway_module_send']) && !empty($common['gateway_module_get']);"),
    ("Mg2", "php", G, "the (lifted) guard ignores a get-only gateway",
     "return !empty($common['gateway_module_send']) || !empty($common['gateway_module_get']);",
     "return !empty($common['gateway_module_send']);"),
    # --- failures in the send loop -------------------------------------------------------------------------
    ("Mp13", "php", U, "the send loop catches Exception again, not Throwable",
     "catch (\\Throwable $ex)", "catch (\\Exception $ex)"),
    ("Mp14", "php", U, "the open transaction is not closed after a failed send",
     "$db->transaction_abort();", "/* dropped */"),
    ("Mp15", "php", U, "the leading <br/> is back on an all-failed message",
     "$message = implode('<br/>', $parts);",
     "$message = ($log_success ? $parts[0] : '') . ($log_error ? '<br/>' . end($parts) : '');"),
    # --- the audit comment -------------------------------------------------------------------------------
    ("Mp16", "php", U, "a comment is written when everything failed too",
     "\t\t\tif ($log_success)\n\t\t\t{\n\t\t\t\ttry\n", "\t\t\tif ($log_success || $log_error)\n\t\t\t{\n\t\t\t\ttry\n"),
    ("Mp17", "php", U, "the old audit is back: read_single + add_comment + bo->update of the whole event",
     CALL,
     "$event = $this->bo->read_single($id); $this->add_comment($event, \"SMS: $message\"); $this->bo->update($event);"),
    ("Mp18", "php", U, "a throw from the comment insert is no longer contained",
     "\t\t\t\tcatch (\\Throwable $e)\n\t\t\t\t{\n\t\t\t\t\t(new Log())->error(array(\n\t\t\t\t\t\t'text'\t => \"SMS sent for event",
     "\t\t\t\tcatch (\\InvalidArgumentException $e)\n\t\t\t\t{\n\t\t\t\t\t(new Log())->error(array(\n\t\t\t\t\t\t'text'\t => \"SMS sent for event"),
    ("Mp19", "php", U, "a failed comment insert is not logged",
     "(new Log())->error(array(\n\t\t\t\t\t\t'text'\t => \"SMS sent for event",
     "(array(\n\t\t\t\t\t\t'text'\t => \"SMS sent for event"),
    # --- the escape (pe-pm 36094) ----------------------------------------------------------------------------
    ("Mx1", "php", U, "the phone numbers are not escaped at all (both lists)",
     "$escape = static fn($phone) => htmlspecialchars((string)$phone, ENT_QUOTES, 'UTF-8');",
     "$escape = static fn($phone) => (string)$phone;"),
    ("Mx2", "php", U, "the sent list is not escaped",
     "implode(',', array_map($escape, $log_success))", "implode(',', $log_success)"),
    ("Mx3", "php", U, "the failed list is not escaped",
     "implode(',', array_map($escape, $log_error))", "implode(',', $log_error)"),
    ("Mx4", "php", U, "only the returned message is escaped, the event-history comment gets the raw numbers",
     CALL,
     "$this->bo->add_single_comment($id, \"SMS: \" . htmlspecialchars_decode($message), 'comment', $this->current_account_fullname());"),
    # --- the author of the audit comment (opus final 36166, F2) --------------------------------------------
    ("Ma1", "php", U, "the button drops the author (back to the default: the account id)",
     CALL, "$this->bo->add_single_comment($id, \"SMS: $message\");"),
    ("Ma2", "php", U, "the button passes the account id as the author",
     CALL, "$this->bo->add_single_comment($id, \"SMS: $message\", 'comment', $this->current_account_id());"),
    ("Ma3", "php", U, "the button passes the author in the type's place",
     CALL, "$this->bo->add_single_comment($id, \"SMS: $message\", $this->current_account_fullname(), 'comment');"),
    ("Ma4", "php", SO, "the so binds a given author as PARAM_INT",
     "'value'\t=> (string)$author,\n\t\t\t\t\t\t'type'\t=> PDO::PARAM_STR",
     "'value'\t=> (string)$author,\n\t\t\t\t\t\t'type'\t=> PDO::PARAM_INT"),
    ("Ma5", "php", SO, "the so ignores a given author (always the account id)",
     "3\t=> $author === null", "3\t=> true"),
    ("Ma6", "php", SO, "the so treats an empty name as not given",
     "3\t=> $author === null", "3\t=> ($author === null || $author === '')"),
    ("Ma7", "php", SO, "the omitted-author path is changed: the account id is bound as a string",
     "'value'\t=> $this->userSettings['account_id'],\n\t\t\t\t\t\t'type'\t=> PDO::PARAM_INT",
     "'value'\t=> $this->userSettings['account_id'],\n\t\t\t\t\t\t'type'\t=> PDO::PARAM_STR"),
    ("Ma8", "php", SO, "the so quotes a given name through db_addslashes again (O'Brien -> O''Brien)",
     "'value'\t=> (string)$author,", "'value'\t=> $this->db->db_addslashes((string)$author),"),
    ("Ma9", "php", BO, "the bo does not forward the author",
     "$this->so->add_single_comment($event_id, $comment, $type, $author);",
     "$this->so->add_single_comment($event_id, $comment, $type);"),
    ("Ma10", "php", ASYNC, "an access-request reminder starts passing an author (no longer the default path)",
     "i adgangskontrollen.\");", "i adgangskontrollen.\", 'comment', 'Cron');"),
    ("Ma11", "php", "src/modules/booking/inc/class.zz_new_caller.inc.php",
     "a new caller of add_single_comment appears in a file nobody listed (it would silently take the default path)",
     None, "<?php\n$bo->add_single_comment($id, 'comment text');\n"),
    # --- event.js ---------------------------------------------------------------------------------------------
    ("Mj1", "js", E, "no ajax error callback (a 500 / die() body / HTML page is silent again)",
     "\t\t\terror: function ()", "\t\t\terrorX: function ()"),
    ("Mj2", "js", E, "the 1 s clear of an error is back",
     "showReceipt(data.status == 'error' ? 'error' : 'msg_good', data.message);",
     "showReceipt(data.status == 'error' ? 'error' : 'msg_good', data.message);\n\t\t\t\tif (data.status == 'error') { setTimeout(function(){ $(\"#sms_receipt\").html(''); }, 1000); }"),
    ("Mj3", "js", E, "a reply with no message is no longer caught (prints «undefined» / nothing)",
     "if (!data || !data.message)", "if (data == null)"),
    ("Mj4", "js", E, "an ok reply is shown in the error class",
     "data.status == 'error' ? 'error' : 'msg_good'", "'error'"),
    ("Mj5", "js", E, "an error reply is shown in the good class",
     "data.status == 'error' ? 'error' : 'msg_good'", "'msg_good'"),
    ("Mj6", "js", E, "the error callback asks for a key nobody defines",
     "\t\t\t{\n\t\t\t\tshowReceipt('error', lang['sms_send_failed']);\n\t\t\t}\n\t\t});",
     "\t\t\t{\n\t\t\t\tshowReceipt('error', lang['sms_send_failed_x']);\n\t\t\t}\n\t\t});"),
    ("Mj7", "js", E, "the request no longer says send_sms",
     "send_sms: true", "send_sms: false"),
    ("Mj8", "js", E, "the confirm is skipped",
     "if (r !== true)", "if (false)"),
    # --- the js_lang token and the lang rows -------------------------------------------------------------------
    ("Mt1", "js", X, "the js_lang token is gone from event_edit.xsl (lang['sms_send_failed'] is undefined on the page)",
     "'percent', 'sms_send_failed')", "'percent')"),
    ("Ml1", "js", LANG.format("no"), "sms_send_failed is gone from the Norwegian (bokmål) file",
     "sms_send_failed\tbooking\tno\t", "sms_send_failed_x\tbooking\tno\t"),
    ("Ml2", "js", LANG.format("nn"), "sms_send_failed is gone from the Nynorsk file",
     "sms_send_failed\tbooking\tnn\t", "sms_send_failed_x\tbooking\tnn\t"),
    ("Ml3", "js", LANG.format("en"), "sms_send_failed is gone from the English file",
     "sms_send_failed\tbooking\ten\t", "sms_send_failed_x\tbooking\ten\t"),
    ("Ml4", "js", LANG.format("no"), "sms_event_no_participants is gone from the Norwegian (bokmål) file",
     "sms_event_no_participants\tbooking\tno\t", "sms_event_no_participants_x\tbooking\tno\t"),
    ("Ml5", "js", LANG.format("nn"), "sms_event_no_participants is gone from the Nynorsk file",
     "sms_event_no_participants\tbooking\tnn\t", "sms_event_no_participants_x\tbooking\tnn\t"),
    ("Ml6", "js", LANG.format("en"), "sms_event_no_participants is gone from the English file",
     "sms_event_no_participants\tbooking\ten\t", "sms_event_no_participants_x\tbooking\ten\t"),
    ("Ml7", "js", LANG.format("nn"), "the reused sms_not_configured is gone from the Nynorsk file",
     "sms_not_configured\tbooking\tnn\t", "sms_not_configured_x\tbooking\tnn\t"),
]


def run_harness(kind, tree):
    env = dict(os.environ)
    env["HD_ROOT"] = tree
    if kind == "php":
        cmd = [PHP, os.path.join(HERE, "run.php")]
    else:
        cmd = [NODE, os.path.join(HERE, "run_js.mjs")]
    p = subprocess.run(cmd, env=env, capture_output=True, text=True)
    out = (p.stdout + p.stderr).strip().splitlines()
    return p.returncode, (out[-1] if out else ""), p.stdout


def main():
    tmp = tempfile.mkdtemp(prefix="event-sms-mut-")
    try:
        tree = os.path.join(tmp, "tree")
        for rel in FILES:
            dst = os.path.join(tree, rel)
            os.makedirs(os.path.dirname(dst), exist_ok=True)
            shutil.copyfile(os.path.join(ROOT, rel), dst)

        for kind in ("php", "js"):
            rc, tail, _ = run_harness(kind, tree)
            print(f"baseline {kind:3} rc={rc}  {tail}")
            if rc != 0:
                print("BASELINE IS RED - mutation results would mean nothing")
                return 1

        survived, crashed = [], []
        for mid, kind, rel, desc, old, new in MUTATIONS:
            path = os.path.join(tree, rel)
            if old is None:
                # the mutation ADDS a file the tree does not have; it must not exist yet
                if os.path.exists(path):
                    print(f"{mid}: MUTATION DID NOT APPLY - {rel} already exists: {desc}")
                    return 1
                original, mutated = None, new
            else:
                original = open(path, encoding="utf-8").read()
                n = original.count(old)
                if n != 1:
                    print(f"{mid}: MUTATION DID NOT APPLY - pattern matched {n} times (need exactly 1): {desc}")
                    return 1
                mutated = original.replace(old, new, 1)
            open(path, "w", encoding="utf-8").write(mutated)
            try:
                rc, tail, stdout = run_harness(kind, tree)
            finally:
                if original is None:
                    os.remove(path)
                else:
                    open(path, "w", encoding="utf-8").write(original)
            m = re.search(r"FAIL (\d+) of (\d+)", tail)
            if rc == 1 and m and "FAIL " in stdout:
                print(f"{mid:5} caught   rc={rc}  {kind:3}  {m.group(1):>2} of {m.group(2)} checks fail  {desc}")
            elif rc == 0:
                print(f"{mid:5} SURVIVED rc={rc}  {kind:3}  {desc}")
                survived.append(mid)
            else:
                print(f"{mid:5} CRASH    rc={rc}  {kind:3}  {tail[:120]}  {desc}")
                crashed.append(mid)

        if survived or crashed:
            if survived:
                print("SURVIVING MUTATIONS:", ", ".join(survived))
            if crashed:
                print("CRASHED (harness died instead of failing a check):", ", ".join(crashed))
            return 1
        print(f"all {len(MUTATIONS)} mutations caught, 0 crashes; both baselines green")
        return 0
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    sys.exit(main())
