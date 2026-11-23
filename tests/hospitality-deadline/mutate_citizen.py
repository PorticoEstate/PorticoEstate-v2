#!/usr/bin/env python3
"""
Mutation check for the citizen-catering harnesses (run_citizen.php and run_closed.mts, task 34384; the
run_closed.mts half rewritten for «read-only, not hidden» on a rejected or cancelled application, task 35894).

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
    # run_citizen.php has required CateringChangeText.php since 6ca0ca454 and this list did not hold it: the baseline died (rc 255).
    "src/modules/booking/services/CateringChangeText.php",
    "src/modules/booking/services/TranslationLangSwitch.php",
    "src/modules/booking/services/HospitalityOrderQuantity.php",
    "src/modules/booking/repositories/HospitalityOrderRepository.php",
    "src/modules/booking/controllers/HospitalityOrderController.php",
    "src/modules/bookingfrontend/services/applications/CateringChangeNotifier.php",
    "src/modules/bookingfrontend/services/applications/CitizenHospitalityOrder.php",
    "src/modules/bookingfrontend/controllers/applications/HospitalityOrderController.php",
    "src/modules/bookingfrontend/routes/Routes.php",
    "src/modules/bookingfrontend/client/src/service/types/api/hospitality.types.ts",
    "src/modules/bookingfrontend/client/src/utils/catering-status.ts",
    "src/modules/bookingfrontend/client/src/components/user/application/catering-overview.tsx",
    "src/modules/bookingfrontend/setup/phpgw_no.lang",
    "src/modules/bookingfrontend/setup/phpgw_en.lang",
    "src/modules/bookingfrontend/setup/phpgw_nn.lang",
    "src/modules/booking/setup/phpgw_no.lang",
    "src/modules/booking/setup/phpgw_en.lang",
    "src/modules/booking/setup/phpgw_nn.lang",
]

C = "src/modules/bookingfrontend/controllers/applications/HospitalityOrderController.php"
K = "src/modules/bookingfrontend/services/applications/CitizenHospitalityOrder.php"
REPO = "src/modules/booking/repositories/HospitalityOrderRepository.php"
R = "src/modules/bookingfrontend/routes/Routes.php"
T = "src/modules/bookingfrontend/client/src/service/types/api/hospitality.types.ts"
CS = "src/modules/bookingfrontend/client/src/utils/catering-status.ts"
OV = "src/modules/bookingfrontend/client/src/components/user/application/catering-overview.tsx"
LANG = "src/modules/bookingfrontend/setup/phpgw_{}.lang"
HOQ = "src/modules/booking/services/HospitalityOrderQuantity.php"
ADM = "src/modules/booking/controllers/HospitalityOrderController.php"
LANGB = "src/modules/booking/setup/phpgw_{}.lang"

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
    # --- (4) a rejected or cancelled application: the orders stay on view, read-only (task 35894) --
    ("Me1", "mts", CS, "CANCELLED is dropped from the list",
     "= ['REJECTED', 'CANCELLED'];", "= ['REJECTED'];"),
    ("Me2", "mts", CS, "REJECTED is misspelt (compiles: status is a string)",
     "= ['REJECTED', 'CANCELLED'];", "= ['REJECTD', 'CANCELLED'];"),
    ("Me3", "mts", CS, "the comparison folds case (a lower-case status turns read-only)",
     "CATERING_CLOSED_APPLICATION_STATUSES.includes(status);", "CATERING_CLOSED_APPLICATION_STATUSES.includes(status.toUpperCase());"),
    ("Me4", "mts", CS, "another status (NEW) turns read-only too",
     "= ['REJECTED', 'CANCELLED'];", "= ['REJECTED', 'CANCELLED', 'NEW'];"),
    ("Me5", "mts", CS, "a missing status turns read-only instead of keeping today's behaviour",
     "status !== null && status !== undefined && CATERING_CLOSED_APPLICATION_STATUSES.includes(status);",
     "status === null || status === undefined || CATERING_CLOSED_APPLICATION_STATUSES.includes(status);"),
    ("Me6", "mts", OV, "the old gate is back in `enabled`: a closed application's orders are not fetched",
     "const enabled = !!serverSettings?.booking_config?.enable_hospitality;",
     "const enabled = !!serverSettings?.booking_config?.enable_hospitality && !isCateringClosed(application.status);"),
    ("Me7", "mts", OV, "the orders query is no longer keyed on `enabled` (it fetches with the setting off)",
     "useHospitalityOrders(enabled ? application.id : undefined)", "useHospitalityOrders(application.id)"),
    ("Me8", "mts", OV, "HANDLEABLE_STATUSES changes (another status's behaviour moves)",
     "const HANDLEABLE_STATUSES = ['NEW', 'PENDING', 'ACCEPTED'];", "const HANDLEABLE_STATUSES = ['NEW', 'PENDING', 'ACCEPTED', 'REJECTED'];"),
    # section or no section (the pure function)
    ("Mf1", "mts", CS, "a closed application hides its orders again",
     "orderCount > 0 || (!isCateringClosed(status) && hospitalityCount > 0)",
     "!isCateringClosed(status) && (orderCount > 0 || hospitalityCount > 0)"),
    ("Mf2", "mts", CS, "a closed application shows a section with no orders in it",
     "orderCount > 0 || (!isCateringClosed(status) && hospitalityCount > 0)",
     "orderCount > 0 || isCateringClosed(status) || hospitalityCount > 0"),
    ("Mf3", "mts", CS, "something on offer alone brings a closed application's section back",
     "(!isCateringClosed(status) && hospitalityCount > 0)", "hospitalityCount > 0"),
    ("Mf4", "mts", CS, "an open application with something to add but no orders loses its section",
     "orderCount > 0 || (!isCateringClosed(status) && hospitalityCount > 0)", "orderCount > 0"),
    # the offer list is not fetched, and a switched-off query cannot blank the section
    ("Mg1", "mts", OV, "the loading guard waits for the offer query on a closed application (blank on a react-query downgrade)",
     "const hospitalitiesLoading = !readOnly && hospitalitiesQuery.isLoading;", "const hospitalitiesLoading = hospitalitiesQuery.isLoading;"),
    ("Mg2", "mts", OV, "the offer query is keyed on `enabled` alone: a closed application fetches the offer list",
     "useAvailableHospitalities(enabled && !readOnly ? application.id : undefined)", "useAvailableHospitalities(enabled ? application.id : undefined)"),
    ("Mg3", "mts", OV, "the component stops asking the tested function (an inline rule again)",
     "if (!showsCateringSection(application.status, orders.length, hospitalities.length)) {", "if (orders.length === 0 && hospitalities.length === 0) {"),
    # no actions, hints or locked-Alert on a closed application
    ("Mh1", "mts", OV, "`handleable` no longer ignores a read-only application",
     "const handleable = !readOnly && HANDLEABLE_STATUSES.includes(application.status);", "const handleable = HANDLEABLE_STATUSES.includes(application.status);"),
    ("Mh2", "mts", OV, "the locked-Alert is built from the server's reason on a read-only application",
     "if (handleable && actions) {", "if (actions) {"),
    ("Mh3", "mts", OV, "\"Rediger\" is offered on the server's word alone",
     "const changeOpen = handleable && !!actions?.change.allowed && !!hospitality;", "const changeOpen = !!actions?.change.allowed && !!hospitality;"),
    ("Mh4", "mts", OV, "\"Avbestill\" is offered on the server's word alone",
     "const cancelOpen = handleable && !!actions?.cancel.allowed;", "const cancelOpen = !!actions?.cancel.allowed;"),
    ("Mh5", "mts", OV, "\"Legg til\" is offered on any status that is not read-only (a draft, an unknown status)",
     "const canAdd = handleable", "const canAdd = !readOnly"),
    ("Mh6", "mts", OV, "the change / cancel rule is derived for a read-only application too",
     "const rules = handleable ? ruleGroups(orders, hospitalities) : [];", "const rules = ruleGroups(orders, hospitalities);"),
    ("Mh7", "mts", OV, "the order dialog is mounted for a read-only application",
     "{!readOnly && (", "{("),
    # the price summary of the application never counts a billed catering order twice (task 39791, D1)
    ("Mk1", "mts", CS, "a BILLED order counts as proven not billed",
     "['order_billed', 'order_status', 'application_closed']", "['order_status', 'application_closed']"),
    ("Mk2", "mts", CS, "a DELIVERED order (status checked before billed) counts as proven",
     "['order_billed', 'order_status', 'application_closed']", "['order_billed', 'application_closed']"),
    ("Mk3", "mts", CS, "a closed application counts as proven",
     "['order_billed', 'order_status', 'application_closed']", "['order_billed', 'order_status']"),
    ("Mk4", "mts", CS, "one proven action is enough (the two are no longer both held to it)",
     "[actions.change, actions.cancel].every(", "[actions.change, actions.cancel].some("),
    # Since 326199b38 the summary's rule is ONE function, showsPriceSummary (its every/some shape is mutated in
    # tests/application-view/mutate_application_view.py); these two mutate how the component asks it (task 40576:
    # the old patterns matched nothing from 326199b38 on, which aborted this run before Mk6).
    ("Mk5", "mts", OV, "the application summary no longer asks about billed at all",
     "const showApplicationSummary = showsPriceSummary(orders, prices.total.inclOre);", "const showApplicationSummary = orders.length > 0;"),
    ("Mk6", "mts", OV, "the billing rule copied back inline (two copies can drift)",
     "const showApplicationSummary = showsPriceSummary(orders, prices.total.inclOre);",
     "const showApplicationSummary = orders.length > 0 && orders.every(order => isProvablyNotBilled(order.actions));"),
    ("Mk7", "mts", OV, "the cancellation asks window.confirm again",
     "const askCancel = (order: IHospitalityOrder) => setCancelTarget(order);",
     "const askCancel = (order: IHospitalityOrder) => { if (!window.confirm('?')) return; setCancelTarget(order); };"),
    # what a read-only application does show
    ("Mi1", "mts", OV, "the note is shown on an open application instead of a read-only one",
     "{readOnly && (", "{!readOnly && ("),
    ("Mi2", "mts", OV, "the note says something else (a wrong key compiles)",
     "{t('bookingfrontend.catering_application_closed')}", "{t('bookingfrontend.catering_overview_empty')}"),
    ("Mi3", "mts", OV, "the kitchen contact is hidden on a read-only application (task 40576: once per kitchen)",
     "rows.filter(row => readOnly || servingState(row.order).locked)", "rows.filter(row => servingState(row.order).locked)"),
    # the note's lang key must exist where the client reads it from
    ("Mj1", "mts", LANG.format("no"), "the note's key is gone from the Norwegian (bokmål) file",
     "catering_application_closed\tbookingfrontend\tno\t", "catering_application_closed_x\tbookingfrontend\tno\t"),
    ("Mj2", "mts", LANG.format("en"), "the note's key is gone from the English file",
     "catering_application_closed\tbookingfrontend\ten\t", "catering_application_closed_x\tbookingfrontend\ten\t"),
    ("Mj3", "mts", LANG.format("nn"), "the note's key is gone from the Nynorsk file",
     "catering_application_closed\tbookingfrontend\tnn\t", "catering_application_closed_x\tbookingfrontend\tnn\t"),
    # --- (7) the quantity of a catering line is a positive WHOLE number (task 47994, C1) ----------------------
    ("Mq1", "php", HOQ, "a fraction is no longer refused (the floor test is gone: 2.5 is read as 2)",
     "if ($raw != floor($raw) || $raw < 1 || $raw > self::MAX) {", "if ($raw < 1 || $raw > self::MAX) {"),
    ("Mq2", "php", HOQ, "a string with a fraction is accepted (\"2.5\" is read as 2)",
     "'/^[0-9]+(?:\\.0+)?$/D'", "'/^[0-9]+(?:\\.[0-9]+)?$/D'"),
    ("Mq3", "php", HOQ, "a string with a trailing newline is accepted (the D modifier is gone)",
     "'/^[0-9]+(?:\\.0+)?$/D'", "'/^[0-9]+(?:\\.0+)?$/'"),
    ("Mq4", "php", HOQ, "zero is a quantity",
     "return $quantity >= 1 && $quantity <= self::MAX ? $quantity : null;", "return $quantity >= 0 && $quantity <= self::MAX ? $quantity : null;"),
    ("Mq5", "php", HOQ, "no upper bound (a whole number above the column's capacity is accepted)",
     "return $quantity >= 1 && $quantity <= self::MAX ? $quantity : null;", "return $quantity >= 1 ? $quantity : null;"),
    ("Mq6", "php", HOQ, "a float above the column's capacity is not refused by value (PHP's (int) wraps 2^64+8192 to 8192)",
     "if ($raw != floor($raw) || $raw < 1 || $raw > self::MAX) {", "if ($raw != floor($raw) || $raw < 1) {"),
    ("Mq7", "php", HOQ, "anything that is not an int, float or digit string is cast with (int) (true, [2] and \"2abc\"-like values pass)",
     "        } else {\n            return null;\n        }\n\n        return $quantity", "        } else {\n            $quantity = (int)$raw;\n        }\n\n        return $quantity"),
    ("Mq8", "php", HOQ, "a line without a quantity is refused instead of meaning 1",
     "return isset($line['quantity']) ? self::whole($line['quantity']) : 1;", "return isset($line['quantity']) ? self::whole($line['quantity']) : null;"),
    ("Mq9", "php", HOQ, "a null quantity is judged instead of meaning 1",
     "return isset($line['quantity']) ? self::whole($line['quantity']) : 1;",
     "return is_array($line) && array_key_exists('quantity', $line) ? self::whole($line['quantity']) : 1;"),
    ("Mq10", "php", C, "createOrder judges nothing (the old float cast and <= 0 test are back)",
     "\t\t\t\t// A whole number (task 47994): judged here, before the first write below, and stored as the int it was read as.\n"
     "\t\t\t\t$quantity = HospitalityOrderQuantity::ofLine($line);\n\t\t\t\tif ($quantity === null) {\n",
     "\t\t\t\t$quantity = isset($line['quantity']) ? (float)$line['quantity'] : 1;\n\t\t\t\tif ($quantity <= 0) {\n"),
    ("Mq11", "php", C, "updateOrder's pre-pass does not refuse: a bad quantity is stored as 1",
     "\t\t\t\t\tif ($quantity === null) {\n\t\t\t\t\t\treturn $this->refuseQuantity($response, $i);\n\t\t\t\t\t}\n\t\t\t\t\t$quantities[$i] = $quantity;",
     "\t\t\t\t\t$quantities[$i] = $quantity ?? 1;"),
    ("Mq12", "php", C, "updateOrder writes the order's own fields BEFORE it judges the quantities (the pre-pass comes after the write)",
     "\t\t\t$quantities = [];\n",
     "\t\t\tif (!empty($updateData)) {\n\t\t\t\t$updateData['modified_by'] = $bookingUserId;\n\t\t\t\t$this->orderRepo->update($orderId, $updateData);\n\t\t\t\t$updateData = [];\n\t\t\t}\n\t\t\t$quantities = [];\n"),
    ("Mq13", "php", C, "the refusal text is not translated",
     "'error' => lang('catering_quantity_not_whole'),", "'error' => 'quantity must be a whole number',"),
    ("Mq14", "php", C, "the refusal always names line 0",
     "'line' => $line,\n", "'line' => 0,\n"),
    ("Mq15", "php", C, "the refusal is a 422, not this controller's 400",
     "'line' => $line,\n\t\t], 400, $response);", "'line' => $line,\n\t\t], 422, $response);"),
    ("Mq16", "php", C, "updateOrder's loop reads the raw quantity again instead of the judged int",
     "\t\t\t\t\t$quantity = $quantities[$i];", "\t\t\t\t\t$quantity = (float)($line['quantity'] ?? 1);"),
    ("Mq17", "php", LANG.format("no"), "the Norwegian (bokmål) text is gone",
     "catering_quantity_not_whole\tbookingfrontend\tno\t", "catering_quantity_not_whole_x\tbookingfrontend\tno\t"),
    ("Mq18", "php", LANG.format("nn"), "the Nynorsk text is gone",
     "catering_quantity_not_whole\tbookingfrontend\tnn\t", "catering_quantity_not_whole_x\tbookingfrontend\tnn\t"),
    ("Mq19", "php", LANG.format("en"), "the English text is gone",
     "catering_quantity_not_whole\tbookingfrontend\ten\t", "catering_quantity_not_whole_x\tbookingfrontend\ten\t"),
    ("Mq20", "php", HOQ, "a float below 1 is not refused by value before the cast (task 48533: (int) wraps -18446744073709549568 to +2048, which the range test accepts)",
     "if ($raw != floor($raw) || $raw < 1 || $raw > self::MAX) {", "if ($raw != floor($raw) || $raw > self::MAX) {"),
    # --- (8) the same rule in the ADMIN order API: addLine and updateLine (task 47994, C1b) --------------------
    ("Ma1", "php", ADM, "addLine judges nothing (the raw body quantity is stored again)",
     "\t\t\t$quantity = HospitalityOrderQuantity::ofLine($body);\n\t\t\tif ($quantity === null) {\n\t\t\t\treturn $this->refuseQuantity($response);\n\t\t\t}\n",
     "\t\t\t$quantity = $body['quantity'] ?? 1;\n"),
    ("Ma2", "php", ADM, "addLine judges the quantity but stores the raw body value (a string stays a string)",
     "'quantity' => $quantity,\n\t\t\t\t'unit_price' => $pricing['effective_price'],", "'quantity' => $body['quantity'] ?? 1,\n\t\t\t\t'unit_price' => $pricing['effective_price'],"),
    ("Ma3", "php", ADM, "addLine judges the quantity BEFORE it asks for the article id",
     "\t\t\tif (empty($body['hospitality_article_id'])) {\n\t\t\t\treturn $this->jsonError($response, 'hospitality_article_id is required');\n\t\t\t}\n\n"
     "\t\t\t// A positive whole number, for every unit (task 47994). An absent or null quantity still means 1.\n"
     "\t\t\t$quantity = HospitalityOrderQuantity::ofLine($body);\n\t\t\tif ($quantity === null) {\n\t\t\t\treturn $this->refuseQuantity($response);\n\t\t\t}\n",
     "\t\t\t$quantity = HospitalityOrderQuantity::ofLine($body);\n\t\t\tif ($quantity === null) {\n\t\t\t\treturn $this->refuseQuantity($response);\n\t\t\t}\n"
     "\t\t\tif (empty($body['hospitality_article_id'])) {\n\t\t\t\treturn $this->jsonError($response, 'hospitality_article_id is required');\n\t\t\t}\n"),
    ("Ma4", "php", ADM, "updateLine judges nothing (the raw body goes to the repository)",
     "\t\t\t\t$quantity = HospitalityOrderQuantity::whole($body['quantity']);\n\t\t\t\tif ($quantity === null) {\n\t\t\t\t\treturn $this->refuseQuantity($response);\n\t\t\t\t}\n",
     "\t\t\t\t$quantity = $body['quantity'];\n"),
    ("Ma5", "php", ADM, "updateLine judges the quantity but does not write the int back (the raw value is stored)",
     "\t\t\t\t$body['quantity'] = $quantity;\n", ""),
    ("Ma6", "php", ADM, "updateLine treats a PRESENT null as absent (it reaches the NOT NULL column)",
     "if (array_key_exists('quantity', $body)) {", "if (isset($body['quantity'])) {"),
    ("Ma7", "php", ADM, "updateLine judges even when the key is absent (a comment-only edit is refused)",
     "if (array_key_exists('quantity', $body)) {", "if (true) {"),
    ("Ma8", "php", ADM, "the refusal is a 500 again",
     "return $this->jsonError($response, lang('booking.hospitality_order_quantity_not_whole'), 400);", "return $this->jsonError($response, lang('booking.hospitality_order_quantity_not_whole'), 500);"),
    ("Ma9", "php", ADM, "the refusal text is not translated",
     "return $this->jsonError($response, lang('booking.hospitality_order_quantity_not_whole'), 400);", "return $this->jsonError($response, 'quantity must be a whole number', 400);"),
    ("Ma10", "php", LANGB.format("no"), "the Norwegian (bokmål) text is gone",
     "hospitality_order_quantity_not_whole\tbooking\tno\t", "hospitality_order_quantity_not_whole_x\tbooking\tno\t"),
    ("Ma11", "php", LANGB.format("nn"), "the Nynorsk text is gone",
     "hospitality_order_quantity_not_whole\tbooking\tnn\t", "hospitality_order_quantity_not_whole_x\tbooking\tnn\t"),
    ("Ma12", "php", LANGB.format("en"), "the English text is gone",
     "hospitality_order_quantity_not_whole\tbooking\ten\t", "hospitality_order_quantity_not_whole_x\tbooking\ten\t"),
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
