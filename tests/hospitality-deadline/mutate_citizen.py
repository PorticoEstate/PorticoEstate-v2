#!/usr/bin/env python3
"""
Mutation check for the citizen-catering harnesses (run_citizen.php and run_closed.mts, task 34384).

Same idea as mutate_mail.py: one deliberate defect at a time, applied to a TEMP COPY of the files the
harnesses read (never to the tree), and the matching harness must go red for each. The baseline on the
same copy must be green first, and every replacement must match exactly once. A mutation counts as
caught only when the harness exits 1 with a FAIL line: any other exit (a crash, a fatal error) is
reported as CRASH and fails the run, because a harness that dies has not looked at anything.

Usage:  python3 tests/hospitality-deadline/mutate_citizen.py
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
    "src/traits/SerializableTrait.php",
    "src/helpers/ResponseHelper.php",
    "src/modules/booking/models/HospitalityOrder.php",
    "src/modules/booking/models/HospitalityOrderLine.php",
    "src/modules/booking/services/HospitalityDeadlineCalculator.php",
    "src/modules/booking/services/HospitalityOrderGuard.php",
    "src/modules/booking/services/HospitalityOrderDiff.php",
    "src/modules/booking/services/CateringText.php",
    "src/modules/booking/services/CateringLangSwitch.php",
    "src/modules/booking/services/CateringMailRenderer.php",
    "src/modules/booking/services/TranslationLangSwitch.php",
    "src/modules/booking/repositories/HospitalityOrderRepository.php",
    "src/modules/booking/controllers/HospitalityOrderController.php",
    "src/modules/bookingfrontend/services/applications/CateringChangeNotifier.php",
    "src/modules/bookingfrontend/services/applications/CitizenHospitalityOrder.php",
    "src/modules/bookingfrontend/controllers/applications/HospitalityOrderController.php",
    "src/modules/bookingfrontend/routes/Routes.php",
    "src/modules/bookingfrontend/client/src/service/types/api/hospitality.types.ts",
    "src/modules/bookingfrontend/client/src/utils/catering-status.ts",
    "src/modules/bookingfrontend/client/src/components/user/application/catering-overview.tsx",
]

C = "src/modules/bookingfrontend/controllers/applications/HospitalityOrderController.php"
K = "src/modules/bookingfrontend/services/applications/CitizenHospitalityOrder.php"
REPO = "src/modules/booking/repositories/HospitalityOrderRepository.php"
R = "src/modules/bookingfrontend/routes/Routes.php"
T = "src/modules/bookingfrontend/client/src/service/types/api/hospitality.types.ts"
CS = "src/modules/bookingfrontend/client/src/utils/catering-status.ts"
OV = "src/modules/bookingfrontend/client/src/components/user/application/catering-overview.tsx"

GUARD_BLOCK = (
    "\t\ttry {\n"
    "\t\t\treturn $this->orderRepo->getOrderWithLines($orderId);\n"
    "\t\t} catch (\\Throwable $e) {\n"
    "\t\t\terror_log(\"Catering order re-read after {$what} failed for order {$orderId}: \" . $e->getMessage());\n"
    "\t\t\treturn null;\n"
    "\t\t}\n"
)
LOG_LINE = "\t\t\terror_log(\"Catering order re-read after {$what} failed for order {$orderId}: \" . $e->getMessage());\n"

# (id, harness, file, description, old, new)
MUTATIONS = [
    # --- (5) what the applicant is sent ---------------------------------------------------------
    ("Mc1", "php", K, "present() stops filtering the order keys (changelog, created_by ... leak)",
     "$out = array_intersect_key($serialized, array_flip(self::ORDER_KEYS));", "$out = $serialized;"),
    ("Mc2", "php", K, "present() stops filtering the line keys",
     "static fn($line) => is_array($line) ? array_intersect_key($line, array_flip(self::LINE_KEYS)) : $line,",
     "static fn($line) => $line,"),
    ("Mc3", "php", K, "the GET extras are not filtered (an officer-only field handed over rides along)",
     "return $out + array_intersect_key($read, array_flip(self::READ_KEYS));", "return $out + $read;"),
    ("Mc4", "php", K, "the allow-list gains `changelog`",
     "        'total_amount',\n        'lines',\n    ];", "        'total_amount',\n        'lines',\n        'changelog',\n    ];"),
    ("Mc5", "php", K, "the allow-list gains `modified_by` (an officer's account id)",
     "        'location_name',\n        'total_amount',\n", "        'location_name',\n        'modified_by',\n        'total_amount',\n"),
    ("Mc6", "php", C, "the POST and PUT bodies skip the allow-list",
     "return CitizenHospitalityOrder::present((new HospitalityOrder($order))->serialize());", "return (new HospitalityOrder($order))->serialize();"),
    ("Mc7", "php", C, "the GET list skips the allow-list",
     "fn($order) => CitizenHospitalityOrder::present((new HospitalityOrder($order))->serialize(), [", "fn($order) => ((new HospitalityOrder($order))->serialize() + ["),
    ("Mc8", "php", REPO, "ADMIN side: getOrderWithLines stops attaching the changelog (the admin control arm must go red)",
     "        $order['changelog'] = $this->getChangelog($orderId);\n", ""),
    ("Mc9", "php", R, "a new bookingfrontend hospitality route with no driver",
     "\t\t$group->delete('/{id}/hospitality-orders/{orderId}', HospitalityOrderController::class . ':deleteOrder');\n",
     "\t\t$group->delete('/{id}/hospitality-orders/{orderId}', HospitalityOrderController::class . ':deleteOrder');\n"
     "\t\t$group->get('/{id}/hospitality-orders/{orderId}', HospitalityOrderController::class . ':getOrder');\n"),
    ("Mc10", "php", R, "Routes.php imports the booking module's same-named controller",
     "use App\\modules\\bookingfrontend\\controllers\\applications\\HospitalityOrderController;", "use App\\modules\\booking\\controllers\\HospitalityOrderController;"),
    ("Mc11", "php", T, "the TS interface can no longer be parsed (0 keys must read red, not green)",
     "export interface IHospitalityOrder {", "export interface IHospitalityOrderRenamed {"),
    ("Mc12", "php", T, "the TS type declares `changelog`",
     "    contact?: IHospitalityOrderContact;\n}", "    contact?: IHospitalityOrderContact;\n    changelog?: unknown;\n}"),
    ("Mc13", "php", T, "the TS type loses a required key the server still sends",
     "    special_requirements: string | null;\n    serving_time_iso: string | null;\n    hospitality_name: string;",
     "    serving_time_iso: string | null;\n    hospitality_name: string;"),
    ("Mc14", "php", C, "a handler claimed order-free (getMenu) starts touching orders",
     "'hospitality_description' => $hospitality['description'] ?? null,",
     "'hospitality_description' => $hospitality['description'] ?? null,\n\t\t\t\t'recent' => $this->orderRepo->getOrdersWithLinesByApplication(1),"),
    # --- (6) a throw after the committed write --------------------------------------------------
    ("Md1", "php", C, "the re-read is not guarded at all (all three handlers)",
     GUARD_BLOCK, "\t\treturn $this->orderRepo->getOrderWithLines($orderId);\n"),
    ("Md2", "php", C, "the guard catches Exception only: a TypeError escapes",
     "\t\t} catch (\\Throwable $e) {\n\t\t\terror_log(\"Catering order re-read after",
     "\t\t} catch (\\Exception $e) {\n\t\t\terror_log(\"Catering order re-read after"),
    ("Md3", "php", C, "the cancel's own WRITE failure is swallowed too (a failed cancel answers 200)",
     "\t\t\t\t$this->orderRepo->updateStatus($orderId, HospitalityOrder::STATUS_CANCELLED, $bookingUserId);\n",
     "\t\t\t\ttry {\n\t\t\t\t\t$this->orderRepo->updateStatus($orderId, HospitalityOrder::STATUS_CANCELLED, $bookingUserId);\n"
     "\t\t\t\t} catch (\\Throwable $e) {\n\t\t\t\t\terror_log('swallowed');\n\t\t\t\t}\n"),
    ("Md4", "php", C, "a failed re-read still yields a snapshot, so the entry and the mail fire on a guess",
     LOG_LINE + "\t\t\treturn null;\n", LOG_LINE + "\t\t\treturn ['id' => $orderId, 'status' => 'cancelled'];\n"),
    ("Md5", "php", C, "the failed re-read is not logged",
     LOG_LINE, ""),
    ("Md6", "php", C, "the fallback body is not {\"id\":N}",
     "return ['id' => $orderId];", "return [];"),
    ("Md7", "php", C, "the read BEFORE the cancel goes through the swallowing helper (a failed pre-read no longer stops the cancel)",
     "\t\t\t\t$before = $this->orderRepo->getOrderWithLines($orderId);\n", "\t\t\t\t$before = $this->readAfterWrite($orderId, 'before');\n"),
    ("Md8", "php", C, "createOrder alone reads the order back unguarded",
     "$order = $this->readAfterWrite($orderId, 'add');", "$order = $this->orderRepo->getOrderWithLines($orderId);"),
    ("Md9", "php", C, "updateOrder alone reads the order back unguarded",
     "$updatedOrder = $this->readAfterWrite($orderId, 'change');", "$updatedOrder = $this->orderRepo->getOrderWithLines($orderId);"),
    ("Md10", "php", C, "deleteOrder alone reads the order back unguarded (the original defect)",
     "$this->readAfterWrite($orderId, 'cancel'),", "$this->orderRepo->getOrderWithLines($orderId),"),
    # --- (4) no catering on a rejected or cancelled application ---------------------------------
    ("Me1", "mts", CS, "CANCELLED is dropped from the list",
     "= ['REJECTED', 'CANCELLED'];", "= ['REJECTED'];"),
    ("Me2", "mts", CS, "REJECTED is misspelt (compiles: status is a string)",
     "= ['REJECTED', 'CANCELLED'];", "= ['REJECTD', 'CANCELLED'];"),
    ("Me3", "mts", CS, "the comparison folds case (a lower-case status hides the section)",
     "CATERING_CLOSED_APPLICATION_STATUSES.includes(status);", "CATERING_CLOSED_APPLICATION_STATUSES.includes(status.toUpperCase());"),
    ("Me4", "mts", CS, "another status (NEW) loses catering too",
     "= ['REJECTED', 'CANCELLED'];", "= ['REJECTED', 'CANCELLED', 'NEW'];"),
    ("Me5", "mts", CS, "a missing status hides the section instead of keeping today's behaviour",
     "status !== null && status !== undefined && CATERING_CLOSED_APPLICATION_STATUSES.includes(status);",
     "status === null || status === undefined || CATERING_CLOSED_APPLICATION_STATUSES.includes(status);"),
    ("Me6", "mts", OV, "the component no longer gates on the predicate",
     " && !isCateringClosed(application.status);", ";"),
    ("Me7", "mts", OV, "the orders query is no longer keyed on `enabled` (a closed application still fetches)",
     "useHospitalityOrders(enabled ? application.id : undefined)", "useHospitalityOrders(application.id)"),
    ("Me8", "mts", OV, "HANDLEABLE_STATUSES changes (another status's behaviour moves)",
     "const HANDLEABLE_STATUSES = ['NEW', 'PENDING', 'ACCEPTED'];", "const HANDLEABLE_STATUSES = ['NEW', 'PENDING', 'ACCEPTED', 'REJECTED'];"),
]


def run_harness(kind, tree):
    env = dict(os.environ)
    env["HD_ROOT"] = tree
    # A worktree has no vendor/: honour VENDOR_AUTOLOAD when the caller set it.
    env["VENDOR_AUTOLOAD"] = os.environ.get("VENDOR_AUTOLOAD") or os.path.join(ROOT, "vendor/autoload.php")
    if kind == "php":
        cmd = [PHP, os.path.join(HERE, "run_citizen.php")]
    else:
        cmd = [NODE, os.path.join(HERE, "run_closed.mts")]
    p = subprocess.run(cmd, env=env, capture_output=True, text=True)
    out = (p.stdout + p.stderr).strip().splitlines()
    return p.returncode, (out[-1] if out else ""), p.stdout


def main():
    tmp = tempfile.mkdtemp(prefix="hd-mutcitizen-")
    try:
        tree = os.path.join(tmp, "tree")
        for rel in FILES:
            dst = os.path.join(tree, rel)
            os.makedirs(os.path.dirname(dst), exist_ok=True)
            shutil.copyfile(os.path.join(ROOT, rel), dst)

        for kind in ("php", "mts"):
            rc, tail, _ = run_harness(kind, tree)
            print(f"baseline {kind} rc={rc}  {tail}")
            if rc != 0:
                print("BASELINE IS RED - mutation results would mean nothing")
                return 1

        survived, crashed = [], []
        for mid, kind, rel, desc, old, new in MUTATIONS:
            path = os.path.join(tree, rel)
            original = open(path, encoding="utf-8").read()
            n = original.count(old)
            if n != 1:
                print(f"{mid}: MUTATION DID NOT APPLY - pattern matched {n} times (need exactly 1): {desc}")
                return 1
            open(path, "w", encoding="utf-8").write(original.replace(old, new, 1))
            try:
                rc, tail, stdout = run_harness(kind, tree)
            finally:
                open(path, "w", encoding="utf-8").write(original)
            m = re.search(r"FAIL (\d+) of (\d+)", tail)
            if rc == 1 and m and "FAIL " in stdout:
                print(f"{mid:5} caught   rc={rc}  {kind}  {m.group(1)} of {m.group(2)} checks fail  {desc}")
            elif rc == 0:
                print(f"{mid:5} SURVIVED rc={rc}  {kind}  {desc}")
                survived.append(mid)
            else:
                print(f"{mid:5} CRASH    rc={rc}  {kind}  {tail[:120]}  {desc}")
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
