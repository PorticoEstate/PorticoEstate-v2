#!/usr/bin/env python3
"""
Mutation check for tests/hospitality-direct-booking-guard/run.php.

  python3 tests/hospitality-direct-booking-guard/mutate.py            # one line per mutant
  python3 tests/hospitality-direct-booking-guard/mutate.py --rows     # ... and, under it, every row that mutant turns RED, by name
  python3 tests/hospitality-direct-booking-guard/mutate.py --rows M59 M65   # only these mutants (ids as printed)

A green harness proves nothing until it is shown to go RED. This copies HospitalityDirectBookingGuard,
breaks ONE rule in the copy, runs the harness against the copy (GUARD_FILE, CONTROLLER_FILE for a
mutant of HospitalityController, MODEL_FILE for the Hospitality model, ORDER_REPOSITORY_FILE for the order
repository), and requires that:
  1. the mutation text was really found exactly once (a mutation that did not apply would "survive"),
  2. the harness exits 1,
  3. the row(s) that are MEANT to catch this mutant are among the failures.
The real file is never touched. Exit 0 = every mutant killed on its own row, 1 = anything else.
"""
import os
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
GUARD = os.path.join(ROOT, "src/modules/booking/services/HospitalityDirectBookingGuard.php")
CONTROLLER = os.path.join(ROOT, "src/modules/booking/controllers/HospitalityController.php")
ORDER_REPOSITORY = os.path.join(ROOT, "src/modules/booking/repositories/HospitalityOrderRepository.php")
MODEL = os.path.join(ROOT, "src/modules/booking/models/Hospitality.php")
PHP = shutil.which("php") or "php"
T = "\t"  # the controller is indented with tabs; its mutants spell that out

# (id, description, old text, new text, row-name fragments that MUST fail[, "controller" / "orderrepo" / "model" when the mutant is of
# HospitalityController / HospitalityOrderRepository / the Hospitality model instead of the guard])
MUTANTS = [
    ("M1", "resource side: an OWN link is ignored",
     "        foreach ($linkRows as $row) {\n            if ((int)($row['hospitality_active'] ?? 0) !== 1) {",
     "        foreach ($linkRows as $row) {\n            if (($row['kind'] ?? '') === 'own') {\n                continue;\n            }\n            if ((int)($row['hospitality_active'] ?? 0) !== 1) {",
     ["res/unset->set(past), own active link"]),
    ("M2", "resource side: a REMOTE link is ignored",
     "if (($row['kind'] ?? '') === 'remote' && (int)($row['link_active'] ?? 0) !== 1) {",
     "if (($row['kind'] ?? '') === 'remote') {",
     ["res/unset->set(past), remote active link"]),
    ("M3", "resource side: a link on an INACTIVE hospitality still counts",
     "            if ((int)($row['hospitality_active'] ?? 0) !== 1) {\n                continue;\n            }\n            if (($row['kind']",
     "            if (($row['kind']",
     ["res/unset->set, own link on INACTIVE hospitality", "res/unset->set, remote link on INACTIVE hospitality"]),
    ("M4", "resource side: an INACTIVE remote row still counts",
     "if (($row['kind'] ?? '') === 'remote' && (int)($row['link_active'] ?? 0) !== 1) {",
     "if (($row['kind'] ?? '') === 'remote' && false) {",
     ["res/unset->set, INACTIVE remote row"]),
    ("M5", "resource side: block a non-transition (set->set is refused too)",
     "        if (self::hasDirectBooking($before) || !self::hasDirectBooking($after)) {\n            return [];",
     "        if (!self::hasDirectBooking($after)) {\n            return [];",
     ["res/set->set(date moved), linked", "res/set->set unchanged, linked"]),
    ("M6", "hospitality side: the hospitality's own resource is not checked",
     "            $becomingLinked[] = (int)$after['resource_id'];",
     "            // mutant: own resource dropped",
     ["hosp/create active, own resource has direct booking"]),
    ("M7", "hospitality side: re-activation does not check the remote rows",
     "                $becomingLinked[] = (int)$resourceId;",
     "                // mutant: remote rows dropped",
     ["hosp/update re-activate, own clean, one remote resource has direct booking"]),
    ("M8", "hospitality side: an INACTIVE hospitality save is checked as if it were active",
     "        if ((int)($after['active'] ?? 0) !== 1) {\n            return [];\n        }\n        $wasActive",
     "        if (false) {\n            return [];\n        }\n        $wasActive",
     ["hosp/create INACTIVE on a direct-booking resource", "hosp/update active->inactive while own moves",
      "hosp/update inactive->inactive, own moved"]),
    ("M9", "hospitality side: block a non-transition (an unrelated edit of an existing conflict is refused)",
     "if (!$wasActive || (int)$before['resource_id'] !== (int)$after['resource_id']) {",
     "if (true) {",
     ["hosp/update active->active, own unchanged"]),
    ("M10", "remote side: a non-transition is refused (re-adding an active row)",
     "            && !$rowWasActive\n",
     "",
     ["remote/re-add an already-active row of an existing conflict"]),
    ("M10b", "remote side: deactivating a row is refused too (the remedy is blocked)",
     "            && $rowWillBeActive\n",
     "",
     ["remote/toggle a dead row to dead"]),
    ("M11", "remote side: an INACTIVE hospitality is checked",
     "(int)($hospitality['active'] ?? 0) === 1\n            && !$rowWasActive",
     "true\n            && !$rowWasActive",
     ["remote/add to an INACTIVE hospitality"]),
    ("M12", "simple_booking is read as direct booking",
     "        $value = $resource['direct_booking'] ?? null;",
     "        $value = ($resource['direct_booking'] ?? null) ?: ($resource['simple_booking'] ?? null);",
     ["has/simple_booking only", "hosp/create active, own resource is simple_booking only",
      "res/unset->unset, simple_booking 0->1 only", "remote/add a simple_booking-only resource"]),
    ("M13", "a FUTURE direct_booking start date does not count",
     "        return !is_numeric($value) || (int)$value !== 0;",
     "        return !is_numeric($value) || ((int)$value !== 0 && (int)$value < time());",
     ["has/FUTURE start date counts", "res/unset->set(FUTURE)", "hosp/create active, own resource has FUTURE",
      "remote/re-activate a dead row, FUTURE"]),
    ("M14", "the empty string counts as a set direct_booking",
     "        if ($value === null || $value === '' || $value === false) {",
     "        if ($value === null || $value === false) {",
     ["has/empty string", "res/before \"\" (cleared) -> set"]),
    # ── F1: the hospitality `active` flag ──────────────────────────────────────────────────────
    ("M15", "active: create reads the flag with a boolean parse again (the F1 defect: \"01\" is skipped)",
     "'active' => self::normaliseActive($body['active'] ?? 1) ?? 1,",
     "'active' => filter_var($body['active'] ?? 1, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,",
     ["active/create guard \"01\"", "active/create guard \"+1\"", "active/create guard \"0001\""]),
    ("M16", "active: update reads the flag with a boolean parse again (re-activating with \"01\" slips through)",
     "(self::normaliseActive($body['active']) ?? 1),",
     "(filter_var($body['active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0),",
     ["active/update guard \"01\"", "active/update guard \"+1\"", "active/update guard \"0001\""]),
    ("M17", "active: create fails OPEN on a value that is not a flag",
     "'active' => self::normaliseActive($body['active'] ?? 1) ?? 1,",
     "'active' => self::normaliseActive($body['active'] ?? 1) ?? 0,",
     ["active/create guard \"abc\"", "active/create guard \"01\""]),
    ("M18", "active: update fails OPEN on a value that is not a flag",
     "(self::normaliseActive($body['active']) ?? 1),",
     "(self::normaliseActive($body['active']) ?? 0),",
     ["active/update guard \"abc\"", "active/update guard \"01\""]),
    ("M19", "active: update always overwrites the stored value (an absent key is read as OFF)",
     "($body['active'] ?? null) === null ? (int)$existing['active'] : ",
     "false ? (int)$existing['active'] : ",
     ["active/update: no active key on an INACTIVE hospitality"]),
    ("M20", "active: a loose == compare reads \"01\", \"+1\", \" 1\" and \"0001\" as 1",
     "if ($value === true || $value === 1 || $value === '1') {",
     "if ($value === true || $value === 1 || $value == '1') {",
     ["active/norm \"01\"", "active/norm \"+1\"", "active/norm \"0001\"", "active/norm \" 1\""]),
    ("M21", "active: the words true / on / yes are accepted as ON",
     "if ($value === true || $value === 1 || $value === '1') {",
     "if ($value === true || $value === 1 || $value === '1' || in_array($value, ['true', 'on', 'yes'], true)) {",
     ["active/norm \"true\"", "active/norm \"on\"", "active/norm \"yes\""]),
    ("M22", "active: a value that is not a flag is read as OFF (a typo would de-activate)",
     "        if ($value === false || $value === 0 || $value === '0') {\n            return 0;\n        }\n        return null;",
     "        if ($value === false || $value === 0 || $value === '0') {\n            return 0;\n        }\n        return 0;",
     ["active/norm \"abc\"", "active/norm \"\"", "active/controller hands the repository \"abc\""]),
    ("M23", "active: any positive int is ON by truthiness (2 would be ON, and Postgres would store 2)",
     "if ($value === true || $value === 1 || $value === '1') {",
     "if ($value === true || (is_int($value) && $value > 0) || $value === '1') {",
     ["active/norm int 2", "active/never stored as 2 int 2"]),
    ("M24", "active: a boolean false is not a flag",
     "if ($value === false || $value === 0 || $value === '0') {",
     "if ($value === 0 || $value === '0') {",
     ["active/norm false"]),
    ("M25", "active: a float 1.0 (json \"1.0\") is accepted as ON",
     "if ($value === true || $value === 1 || $value === '1') {",
     "if ($value === true || $value === 1 || $value === 1.0 || $value === '1') {",
     ["active/norm float 1.0"]),
    ("M26", "controller: the RAW value is passed through to the repository (the normalised one is not written back)",
     "\t\t$body['active'] = $active;\n\t\treturn true;",
     "\t\treturn true;",
     ["active/controller hands the repository true", "active/controller hands the repository \"1\"",
      "active/controller hands the repository \"0\"", "active/controller hands the repository false"],
     "controller"),
    ("M27", "controller: a value that is not a flag is accepted and handed on as it came",
     "\t\tif ($active === null) {\n\t\t\treturn false;\n\t\t}",
     "\t\tif ($active === null) {\n\t\t\treturn true;\n\t\t}",
     ["active/controller hands the repository \"01\"", "active/controller hands the repository \"abc\""],
     "controller"),
    ("M28", "controller: store() no longer reads active at all",
     "\t\t\tif (!$this->normaliseActive($body)) {\n\t\t\t\treturn $this->refuseInvalidActive($response);\n\t\t\t}\n\t\t\tif (!$this->repository->resourceExists(",
     "\t\t\tif (!$this->repository->resourceExists(",
     ["active/source order: store() reads active before the guard", "active/source order: store() reads active before the repository"],
     "controller"),
    ("M29", "controller: update() no longer reads active at all",
     "\t\t\tif (!$this->normaliseActive($body)) {\n\t\t\t\treturn $this->refuseInvalidActive($response);\n\t\t\t}\n\t\t\tif (array_key_exists('resource_id', $body) && !$this->repository->resourceExists(",
     "\t\t\tif (array_key_exists('resource_id', $body) && !$this->repository->resourceExists(",
     ["active/source order: update() reads active before the guard", "active/source order: update() reads active before the repository"],
     "controller"),
    ("M30", "controller: an ABSENT active is always set (a plain rename of a hospitality would switch it off)",
     "\t\tif (($body['active'] ?? null) === null) {\n\t\t\tunset($body['active']);\n\t\t\treturn true;\n\t\t}",
     "\t\tif (($body['active'] ?? null) === null) {\n\t\t\t$body['active'] = 0;\n\t\t\treturn true;\n\t\t}",
     ["active/controller: an ABSENT active stays absent", "active/controller: a null active is dropped"],
     "controller"),
    ("M31", "controller: a null active is kept in the body (update would write NULL)",
     "\t\t\tunset($body['active']);\n",
     "",
     ["active/controller: a null active is dropped"],
     "controller"),

    # ── `resource_id`: ONE reading for the guard and the repository (Postgres 16+ reads "0x5D" as 93; PHP (int) as 0) ─────
    ("M32", "resource_id: the old (int) reading ('0x5D' is resource 0, Postgres 16+ stores 93)",
     "    public static function normaliseResourceId($value): ?int\n    {\n",
     "    public static function normaliseResourceId($value): ?int\n    {\n        return (int)$value;\n",
     ['resid/norm "0x5D"', 'resid/norm "9_3"', 'resid/(int) would read another resource than Postgres 16+ stores']),
    ("M33", "resource_id: the regex lacks the D modifier, so a final newline passes (Postgres reads '93\\\\n' as 93)",
     "preg_match('/^[0-9]+$/D', $value)", "preg_match('/^[0-9]+$/', $value)",
     ['resid/norm "93\\n"']),
    ("M34", "resource_id: a sign and surrounding spaces are tolerated",
     "preg_match('/^[0-9]+$/D', $value)", "preg_match('/^\\s*\\+?[0-9]+\\s*$/D', $value)",
     ['resid/norm "+93"', 'resid/norm " 93"']),
    ("M35", "resource_id: a ten-digit string above the column maximum is not compared ('2147483648')",
     " || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)", "",
     ['resid/norm "2147483648"']),
    ("M36", "resource_id: a string with more than ten digits is not refused",
     "strlen($digits) > strlen($max) || ", "",
     ['resid/norm "99999999999999999999"']),
    ("M37", "resource_id: the int 0 is a resource",
     "return ($value >= 1 && $value <= self::MAX_RESOURCE_ID) ? $value : null;",
     "return ($value >= 0 && $value <= self::MAX_RESOURCE_ID) ? $value : null;",
     ['resid/norm int 0']),
    ("M38", "resource_id: an int has no upper bound",
     "return ($value >= 1 && $value <= self::MAX_RESOURCE_ID) ? $value : null;",
     "return ($value >= 1) ? $value : null;",
     ['resid/norm int 2147483648', 'resid/norm int PHP_INT_MAX']),
    ("M39", "resource_id: a string of only zeros is a resource ('0' -> 0)",
     "if ($digits === '' || ", "if (",
     ['resid/norm "0"', 'resid/norm "00"']),
    ("M40", "resource_id: create's guard reads the resource with (int) again",
     "'resource_id' => self::requireResourceId($body['resource_id'] ?? null),",
     "'resource_id' => (int)($body['resource_id'] ?? 0),",
     ['resid/create guard "0x5D"']),
    ("M41", "resource_id: update's guard reads the resource with (int) again",
     "array_key_exists('resource_id', $body) ? self::requireResourceId($body['resource_id']) :",
     "array_key_exists('resource_id', $body) ? (int)$body['resource_id'] :",
     ['resid/update guard "0x5D"']),
    ("M42", "resource_id: the guard fails OPEN on a value that is not a resource id (reads it as 0)",
     "throw new \\InvalidArgumentException('resource_id is not a resource id');", "return 0;",
     ['resid/create guard "abc"', 'resid/update guard "0x5D"']),
    ("M43", "controller: the normalised int is not written back, so the repository would bind the raw text",
     T*2 + "$body['resource_id'] = $resourceId;\n" + T*2 + "return true;\n" + T + "}\n\n" + T + "/**\n" + T + " * 422 for a `resource_id` that is not",
     T*2 + "return true;\n" + T + "}\n\n" + T + "/**\n" + T + " * 422 for a `resource_id` that is not",
     ['resid/controller hands the repository "093"'], "controller"),
    ("M44", "controller: an ABSENT resource_id is refused (a rename of a hospitality would 422)",
     "if (!array_key_exists('resource_id', $body)) {\n" + T*3 + "return true;",
     "if (!array_key_exists('resource_id', $body)) {\n" + T*3 + "return false;",
     ['resid/an ABSENT resource_id stays absent'], "controller"),
    ("M45", "controller: a null resource_id is treated as absent (update would write NULL)",
     "if (!array_key_exists('resource_id', $body)) {",
     "if (($body['resource_id'] ?? null) === null) {",
     ['resid/a null resource_id is refused'], "controller"),
    ("M46", "controller: a value that is not a resource id is accepted and handed on as it came",
     "if ($resourceId === null) {\n" + T*3 + "return false;",
     "if ($resourceId === null) {\n" + T*3 + "return true;",
     ['resid/controller hands the repository "0x5D"'], "controller"),
    ("M47", "controller: store() does not read resource_id at all",
     T*3 + "if (!$this->normaliseResourceId($body)) {\n" + T*4 + "return $this->refuseResourceId($response, 'invalid_resource_id');\n" + T*3 + "}\n"
       + T*3 + "if (!$this->normaliseActive($body)) {\n" + T*4 + "return $this->refuseInvalidActive($response);\n" + T*3 + "}\n"
       + T*3 + "if (!$this->repository->resourceExists(",
     T*3 + "if (!$this->normaliseActive($body)) {\n" + T*4 + "return $this->refuseInvalidActive($response);\n" + T*3 + "}\n"
       + T*3 + "if (!$this->repository->resourceExists(",
     ['resid/source order: store() reads resource_id before the guard'], "controller"),
    ("M48", "controller: update() does not read resource_id at all",
     T*3 + "if (!$this->normaliseResourceId($body)) {\n" + T*4 + "return $this->refuseResourceId($response, 'invalid_resource_id');\n" + T*3 + "}\n"
       + T*3 + "if (!$this->normaliseActive($body)) {\n" + T*4 + "return $this->refuseInvalidActive($response);\n" + T*3 + "}\n"
       + T*3 + "if (array_key_exists('resource_id', $body) &&",
     T*3 + "if (!$this->normaliseActive($body)) {\n" + T*4 + "return $this->refuseInvalidActive($response);\n" + T*3 + "}\n"
       + T*3 + "if (array_key_exists('resource_id', $body) &&",
     ['resid/source order: update() reads resource_id before the guard'], "controller"),
    ("M49", "controller: store() does not check that the resource exists (the foreign key answers with a 500)",
     T*3 + "if (!$this->repository->resourceExists($body['resource_id'])) {\n" + T*4 + "return $this->refuseResourceId($response, 'unknown_resource');\n" + T*3 + "}\n",
     "",
     ['resid/source order: store() checks the resource exists before the guard'], "controller"),
    ("M50", "controller: update() does not check that the resource exists",
     T*3 + "if (array_key_exists('resource_id', $body) && !$this->repository->resourceExists($body['resource_id'])) {\n" + T*4 + "return $this->refuseResourceId($response, 'unknown_resource');\n" + T*3 + "}\n",
     "",
     ['resid/source order: update() checks the resource exists before the guard'], "controller"),
    ("M51", "controller: the two rulings (Q1, Q2) are asked on every save, not only when the main moves",
     "if ($mainResourceChanged) {", "if (true) {",
     ['q/only an actual move of the main resource asks them'], "controller"),
    ("M52", "controller: Q1 (open orders at the old main) is never asked",
     "$this->mainIsRemoteLocationRefusal($existing, $body['resource_id'])\n" + T*5 + "?? $this->openOrdersAtMainRefusal($existing);",
     "$this->mainIsRemoteLocationRefusal($existing, $body['resource_id']);",
     ['q/source order: update() asks the Q1 method after the Q2 method'], "controller"),
    ("M53", "controller: the broadcast does not tell the other admins to rebuild the Resources tab",
     "$mainResourceChanged ? 'main_resource' : 'details'", "'details'",
     ['q/the broadcast tells other admins'], "controller"),
    ("M54", "controller: Q1 counts through its own query instead of the one definition of 'open'",
     "$open = $this->orderRepository->countOpenAtLocation((int)$existing['id'], (int)$existing['resource_id']);",
     "$open = 0; // serving_time_iso > now() AND status <> 'cancelled'",
     ['q/the Q1 method counts through the one definition', 'q/"open" is not spelled in the controller'], "controller"),
    ("M55", "order repository: a second copy of the final statuses instead of the order guard's list",
     "HospitalityOrderGuard::FINAL_ORDER_STATUSES // constants, never request input",
     "['cancelled', 'delivered'] // a second copy",
     ['q/the definition of "open" reads the final statuses from the order guard'], "orderrepo"),
    ("M56", "order repository: an order with NO serving time is not open (it would be left behind by a move)",
     "AND (serving_time_iso IS NULL OR serving_time_iso > (now() AT TIME ZONE 'UTC'))",
     "AND serving_time_iso > (now() AT TIME ZONE 'UTC')",
     ['q/"open" counts an order with NO serving time as open'], "orderrepo"),
    ("M57", "order repository: 'not started yet' is judged in the database session's zone, not as naive UTC",
     "serving_time_iso > (now() AT TIME ZONE 'UTC'))", "serving_time_iso > now())",
     ['q/"open" compares with the current time as naive UTC'], "orderrepo"),
    ("M58", "order repository: an order is open while its serving time is in the PAST (`<`, mutant OM6 of fact 45960)",
     "serving_time_iso > (now() AT TIME ZONE 'UTC'))", "serving_time_iso < (now() AT TIME ZONE 'UTC'))",
     ['q/"open" means the serving time is still AHEAD'], "orderrepo"),

    # ── the offer description: at most 70 Unicode code points (the model), judged by store() and update() (the controller) ─────
    # The rule is one statement; each mutant replaces it whole.
    ("M59", "description: bytes instead of code points (`strlen` for `mb_strlen`: ae-oe-aa and an emoji count 2 and 4)",
     "return is_string($description) && mb_strlen($description, 'UTF-8') > self::DESCRIPTION_MAX_LENGTH;",
     "return is_string($description) && strlen($description) > self::DESCRIPTION_MAX_LENGTH;",
     ['desc/update 70 code points of ae-oe-aa (140 bytes)', 'desc/store 70 code points of ae-oe-aa (140 bytes)',
      'desc/update 70 x emoji (70 code points, 280 bytes)', 'desc/store 70 x emoji (70 code points, 280 bytes)'], "model"),
    ("M60", "description: `>=` 70, so the 70th code point is already too long",
     "return is_string($description) && mb_strlen($description, 'UTF-8') > self::DESCRIPTION_MAX_LENGTH;",
     "return is_string($description) && mb_strlen($description, 'UTF-8') >= self::DESCRIPTION_MAX_LENGTH;",
     ['desc/update 70 x "a" (the limit)', 'desc/store 70 x "a" (the limit)'], "model"),
    ("M61", "description: the text is trimmed before it is counted",
     "return is_string($description) && mb_strlen($description, 'UTF-8') > self::DESCRIPTION_MAX_LENGTH;",
     "return is_string($description) && mb_strlen(trim($description), 'UTF-8') > self::DESCRIPTION_MAX_LENGTH;",
     ['desc/update 70 x "a" + a trailing space = 71 (no trim)', 'desc/update a leading space + 70 x "a" = 71 (no trim)',
      'desc/update 70 x "a" + a trailing newline = 71 (no trim)', 'desc/update 71 spaces (a trim would make it empty)'], "model"),
    ("M62", "description: graphemes instead of code points (a combining mark does not count on its own)",
     "return is_string($description) && mb_strlen($description, 'UTF-8') > self::DESCRIPTION_MAX_LENGTH;",
     "return is_string($description) && preg_match_all('/\\X/u', $description) > self::DESCRIPTION_MAX_LENGTH;",
     ['desc/update 70 x "a" + a combining acute = 71 code points (70 graphemes)',
      'desc/update 36 x decomposed e-acute = 72 code points (36 graphemes)'], "model"),
    ("M63", "description: NULL and the empty string are refused",
     "return is_string($description) && mb_strlen($description, 'UTF-8') > self::DESCRIPTION_MAX_LENGTH;",
     "return $description === null || $description === '' || (is_string($description) && mb_strlen($description, 'UTF-8') > self::DESCRIPTION_MAX_LENGTH);",
     ['desc/update NULL', 'desc/store NULL', 'desc/update the empty string', 'desc/store the empty string'], "model"),
    ("M64", "description: a non-string, non-null value is refused too (the pinned current behaviour flips)",
     "return is_string($description) && mb_strlen($description, 'UTF-8') > self::DESCRIPTION_MAX_LENGTH;",
     "return is_string($description) ? mb_strlen($description, 'UTF-8') > self::DESCRIPTION_MAX_LENGTH : $description !== null;",
     ['desc/update pinned, see RECORD 45803: a non-string description (int 5)', 'desc/store pinned, see RECORD 45803: a non-string description (int 5)',
      'desc/update pinned, see RECORD 45803: a non-string description (an empty array)'], "model"),
    ("M65", "description: update() judges the STORED description when the body does not carry one",
     T*3 + "if (array_key_exists('resource_id', $body) && !$this->repository->resourceExists($body['resource_id'])) {\n"
       + T*4 + "return $this->refuseResourceId($response, 'unknown_resource');\n" + T*3 + "}\n\n" + T*3 + "if (self::descriptionRefused($body)) {",
     T*3 + "if (array_key_exists('resource_id', $body) && !$this->repository->resourceExists($body['resource_id'])) {\n"
       + T*4 + "return $this->refuseResourceId($response, 'unknown_resource');\n" + T*3 + "}\n\n"
       + T*3 + "if (self::descriptionRefused($body + ['description' => $existing['description'] ?? null])) {",
     ['desc/update a name-only PUT on an offer whose STORED description is 128 code points'], "controller"),
    ("M66", "description: store() does not judge the description at all",
     T*3 + "if (self::descriptionRefused($body)) {\n" + T*4 + "return $this->refuseDescriptionTooLong($response);\n" + T*3 + "}\n\n"
       + T*3 + "// A resource with direct booking cannot be linked to hospitality (operator 2026-11-11).",
     T*3 + "// A resource with direct booking cannot be linked to hospitality (operator 2026-11-11).",
     ['desc/store 71 x "a" (one over)', 'desc/store 71 x emoji (71 code points)'], "controller"),
    ("M67", "description: update() does not judge the description at all",
     T*3 + "if (self::descriptionRefused($body)) {\n" + T*4 + "return $this->refuseDescriptionTooLong($response);\n" + T*3 + "}\n\n"
       + T*3 + "// Moving the hospitality to a resource, or re-activating it, links resources to it again:",
     T*3 + "// Moving the hospitality to a resource, or re-activating it, links resources to it again:",
     ['desc/update 71 x "a" (one over)', 'desc/update 71 x emoji (71 code points)'], "controller"),
    ("M68", "refusal order swapped: update() judges the description BEFORE reading resource_id",
     "$body = $request->getParsedBody() ?? json_decode($request->getBody()->getContents(), true) ?? [];\n\n"
       + T*3 + "// resource_id and active are each read ONCE",
     "$body = $request->getParsedBody() ?? json_decode($request->getBody()->getContents(), true) ?? [];\n\n"
       + T*3 + "if (self::descriptionRefused($body)) {\n" + T*4 + "return $this->refuseDescriptionTooLong($response);\n" + T*3 + "}\n\n"
       + T*3 + "// resource_id and active are each read ONCE",
     ['combo/update a bad resource_id AND a 71-character description'], "controller"),
    ("M69", "refusal order swapped: store() judges the description BEFORE reading resource_id",
     T*4 + "return $response->withHeader('Content-Type', 'application/json')->withStatus(400);\n" + T*3 + "}\n\n"
       + T*3 + "// resource_id and active are each read ONCE",
     T*4 + "return $response->withHeader('Content-Type', 'application/json')->withStatus(400);\n" + T*3 + "}\n\n"
       + T*3 + "if (self::descriptionRefused($body)) {\n" + T*4 + "return $this->refuseDescriptionTooLong($response);\n" + T*3 + "}\n\n"
       + T*3 + "// resource_id and active are each read ONCE",
     ['combo/store a bad resource_id AND a 71-character description'], "controller"),

    # ── Q1 and Q2 of the main resource, run through update() ─────────────────────────────────────────────────────────────────
    ("M70", "Q1/Q2: a refusal is computed and never returned, so the write happens (OM4 of fact 45960)",
     "if ($refusal !== null) {\n" + T*5 + "return $this->refuse($response, $refusal);",
     "if ($refusal !== null && false) {\n" + T*5 + "return $this->refuse($response, $refusal);",
     ['move/Q1 a main move with open orders at the old main', 'move/Q2 a move onto an active delivery location'], "controller"),
    ("M71", "Q1: the refusal is computed and thrown away, Q2's is still returned",
     "$this->mainIsRemoteLocationRefusal($existing, $body['resource_id'])\n" + T*5 + "?? $this->openOrdersAtMainRefusal($existing);",
     "$this->mainIsRemoteLocationRefusal($existing, $body['resource_id']);\n" + T*4 + "$this->openOrdersAtMainRefusal($existing); // computed, not returned",
     ['move/Q1 a main move with open orders at the old main'], "controller"),
    ("M72", "Q2 (the new main is an active delivery location) is skipped",
     "$this->mainIsRemoteLocationRefusal($existing, $body['resource_id'])\n" + T*5 + "?? $this->openOrdersAtMainRefusal($existing);",
     "$this->openOrdersAtMainRefusal($existing);",
     ['move/Q2 a move onto an active delivery location', 'move/Q2 wins over Q1 when both apply'], "controller"),
    ("M73", "Q1 is asked AFTER the write: the move is saved, and the refusal comes back for a row that has already changed",
     T*4 + "$refusal = $this->mainIsRemoteLocationRefusal($existing, $body['resource_id'])\n" + T*5 + "?? $this->openOrdersAtMainRefusal($existing);\n"
       + T*4 + "if ($refusal !== null) {\n" + T*5 + "return $this->refuse($response, $refusal);\n" + T*4 + "}\n" + T*3 + "}\n\n"
       + T*3 + "$accountId = (int)$this->userSettings['account_id'];\n" + T*3 + "$body['modified_by'] = $accountId;\n\n"
       + T*3 + "$this->repository->update($id, $body);\n",
     T*4 + "$refusal = $this->mainIsRemoteLocationRefusal($existing, $body['resource_id']);\n"
       + T*4 + "if ($refusal !== null) {\n" + T*5 + "return $this->refuse($response, $refusal);\n" + T*4 + "}\n" + T*3 + "}\n\n"
       + T*3 + "$accountId = (int)$this->userSettings['account_id'];\n" + T*3 + "$body['modified_by'] = $accountId;\n\n"
       + T*3 + "$this->repository->update($id, $body);\n"
       + T*3 + "if ($mainResourceChanged) {\n" + T*4 + "$refusal = $this->openOrdersAtMainRefusal($existing);\n"
       + T*4 + "if ($refusal !== null) {\n" + T*5 + "return $this->refuse($response, $refusal);\n" + T*4 + "}\n" + T*3 + "}\n",
     ['move/Q1 a main move with open orders at the old main'], "controller"),
    ("M74", "refusal order swapped: Q1 (open orders) is asked before Q2 (delivery location)",
     "$this->mainIsRemoteLocationRefusal($existing, $body['resource_id'])\n" + T*5 + "?? $this->openOrdersAtMainRefusal($existing);",
     "$this->openOrdersAtMainRefusal($existing)\n" + T*5 + "?? $this->mainIsRemoteLocationRefusal($existing, $body['resource_id']);",
     ['move/Q2 wins over Q1 when both apply'], "controller"),
    ("M75", "Q1 counts the orders at the hospitality's own id instead of the old main resource (OM5 of fact 45960)",
     "$open = $this->orderRepository->countOpenAtLocation((int)$existing['id'], (int)$existing['resource_id']);",
     "$open = $this->orderRepository->countOpenAtLocation((int)$existing['id'], (int)$existing['id']);",
     ['move/Q1 a main move with open orders at the old main', 'move/Q1 counts the open orders of THIS hospitality at the OLD main'], "controller"),
    ("M76", "Q1 and Q2 are asked BEFORE the direct-booking guard (a move to a direct-booking resource answers with the wrong refusal)",
     T*3 + "$refusal = $this->directBookingGuard->checkHospitalityUpdate($existing, $body);\n"
       + T*3 + "if ($refusal !== null) {\n" + T*4 + "return $this->refuseDirectBookingConflict($response, $refusal);\n" + T*3 + "}\n\n"
       + T*3 + "// A real move of the main resource (the key may also arrive unchanged, from a client that sends the whole\n"
       + T*3 + "// row). Two rulings by the operator, each in one method: see openOrdersAtMainRefusal() and\n"
       + T*3 + "// mainIsRemoteLocationRefusal().\n"
       + T*3 + "$mainResourceChanged = array_key_exists('resource_id', $body) && $body['resource_id'] !== (int)$existing['resource_id'];\n"
       + T*3 + "if ($mainResourceChanged) {\n"
       + T*4 + "$refusal = $this->mainIsRemoteLocationRefusal($existing, $body['resource_id'])\n" + T*5 + "?? $this->openOrdersAtMainRefusal($existing);\n"
       + T*4 + "if ($refusal !== null) {\n" + T*5 + "return $this->refuse($response, $refusal);\n" + T*4 + "}\n" + T*3 + "}\n",
     T*3 + "$mainResourceChanged = array_key_exists('resource_id', $body) && $body['resource_id'] !== (int)$existing['resource_id'];\n"
       + T*3 + "if ($mainResourceChanged) {\n"
       + T*4 + "$refusal = $this->mainIsRemoteLocationRefusal($existing, $body['resource_id'])\n" + T*5 + "?? $this->openOrdersAtMainRefusal($existing);\n"
       + T*4 + "if ($refusal !== null) {\n" + T*5 + "return $this->refuse($response, $refusal);\n" + T*4 + "}\n" + T*3 + "}\n"
       + T*3 + "$refusal = $this->directBookingGuard->checkHospitalityUpdate($existing, $body);\n"
       + T*3 + "if ($refusal !== null) {\n" + T*4 + "return $this->refuseDirectBookingConflict($response, $refusal);\n" + T*3 + "}\n",
     ['move/the direct-booking guard speaks before Q2 and Q1'], "controller"),
    ("M77", "a move of the main resource is detected the wrong way round (`===`: a real move is a no-change, a no-change is a move; OM2 of fact 45960)",
     "$body['resource_id'] !== (int)$existing['resource_id'];", "$body['resource_id'] === (int)$existing['resource_id'];",
     ['move/Q1 a main move with open orders at the old main', 'move/no main change, the same resource_id (int) sent back with the rest of the row'], "controller"),
]


def run(guard_file, controller_file=CONTROLLER, order_repository_file=ORDER_REPOSITORY, model_file=MODEL):
    env = dict(os.environ)
    env["GUARD_FILE"] = guard_file
    env["CONTROLLER_FILE"] = controller_file
    env["ORDER_REPOSITORY_FILE"] = order_repository_file
    env["MODEL_FILE"] = model_file
    p = subprocess.run([PHP, os.path.join(HERE, "run.php")], env=env, capture_output=True, text=True)
    return p.returncode, p.stdout + p.stderr


def main():
    args = sys.argv[1:]
    show_rows = "--rows" in args
    only = {a for a in args if not a.startswith("--")}
    unknown = only - {m[0] for m in MUTANTS}
    if unknown:
        print(f"UNKNOWN MUTANT ID(S) {sorted(unknown)}: nothing was run")
        return 1
    tmp = tempfile.mkdtemp(prefix="hdbg-mut-")
    bad = 0
    try:
        with open(GUARD, encoding="utf-8") as fh:
            original = fh.read()
        with open(CONTROLLER, encoding="utf-8") as fh:
            original_controller = fh.read()
        with open(ORDER_REPOSITORY, encoding="utf-8") as fh:
            original_order_repository = fh.read()
        with open(MODEL, encoding="utf-8") as fh:
            original_model = fh.read()

        base = os.path.join(tmp, "baseline.php")
        with open(base, "w", encoding="utf-8") as fh:
            fh.write(original)
        base_controller = os.path.join(tmp, "baselineController.php")
        with open(base_controller, "w", encoding="utf-8") as fh:
            fh.write(original_controller)
        rc, out = run(base, base_controller)
        print(f"baseline rc={rc}  {out.strip().splitlines()[-1]}")
        if rc != 0:
            print("BASELINE IS RED - mutation results would mean nothing")
            return 1

        for mid, desc, old, new, must_fail, *target in MUTANTS:
            if only and mid not in only:
                continue
            kind = target[0] if target else "guard"
            source = {"guard": original, "controller": original_controller, "orderrepo": original_order_repository, "model": original_model}[kind]
            count = source.count(old)
            if count != 1:
                print(f"{mid:4} NOT APPLIED ({count} matches) - {desc}")
                bad += 1
                continue
            path = os.path.join(tmp, f"{mid}.php")
            with open(path, "w", encoding="utf-8") as fh:
                fh.write(source.replace(old, new))
            lint = subprocess.run([PHP, "-l", path], capture_output=True, text=True)
            if lint.returncode != 0:
                print(f"{mid:4} MUTANT DOES NOT PARSE - {desc}: {lint.stdout.strip()}")
                bad += 1
                continue
            if kind == "controller":
                rc, out = run(base, path)
            elif kind == "orderrepo":
                rc, out = run(base, base_controller, path)
            elif kind == "model":
                rc, out = run(base, base_controller, ORDER_REPOSITORY, path)
            else:
                rc, out = run(path, base_controller)
            failed = [l for l in out.splitlines() if l.startswith("FAIL")]
            missing = [m for m in must_fail if not any(m in l for l in failed)]
            if rc != 1:
                print(f"{mid:4} SURVIVED (rc={rc}) - {desc}")
                bad += 1
            elif missing:
                print(f"{mid:4} killed, but NOT on the intended row(s) {missing} - {desc}")
                bad += 1
            else:
                print(f"{mid:4} killed on {len(failed)} row(s), incl. the intended ones - {desc}")
            if show_rows:
                for line in failed:
                    print("       RED  " + line[len("FAIL ["):].split("] got ", 1)[0])
    finally:
        shutil.rmtree(tmp, ignore_errors=True)

    print("ALL MUTANTS KILLED ON THEIR OWN ROW" if bad == 0 else f"{bad} MUTANT PROBLEM(S)")
    return 0 if bad == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
