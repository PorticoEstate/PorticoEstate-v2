#!/usr/bin/env python3
"""
Mutation check for the catering amount bound and the one-transaction write (§49596): run.php (hermetic) and run_pg.php
(scratch PostgreSQL) must turn RED for every mutant below.

Each mutant is applied to a COPY of the files the harnesses read from HD_ROOT (never to the tree). Every replacement must
match exactly once, else the mutant is reported BAD (its text drifted from the code). The unmutated copy must be green
first. A mutant counts as caught when either harness exits 1; an exit of anything else is a crash and is reported as such.

Usage:  python3 tests/hospitality-amount/mutate.py      (PHP_BIN overrides the binary)
run_pg.php needs HD_PG_DSN, HD_PG_USER, HD_PG_PASS; without them it is SKIPPED (exit 3) and only run.php is used, which
leaves the transaction mutants (T*) uncaught by design: the run then says so and exits 3, never green.
"""
import os
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(HERE))
PHP = os.environ.get("PHP_BIN", "php")

FILES = [
    "src/traits/SerializableTrait.php",
    "src/helpers/ResponseHelper.php",
    "src/modules/booking/models/HospitalityOrder.php",
    "src/modules/booking/models/HospitalityOrderLine.php",
    "src/modules/booking/services/HospitalityDeadlineCalculator.php",
    "src/modules/booking/services/HospitalityOrderGuard.php",
    "src/modules/booking/services/HospitalityOrderDiff.php",
    "src/modules/booking/services/HospitalityOrderQuantity.php",
    "src/modules/booking/services/HospitalityOrderAmount.php",
    "src/modules/booking/services/CateringText.php",
    "src/modules/booking/services/CateringLangSwitch.php",
    "src/modules/booking/services/CateringMailRenderer.php",
    "src/modules/booking/services/CateringChangeText.php",
    "src/modules/booking/services/TranslationLangSwitch.php",
    "src/modules/booking/repositories/HospitalityOrderRepository.php",
    "src/modules/booking/repositories/HospitalityArticleRepository.php",
    "src/modules/bookingfrontend/services/applications/CateringChangeNotifier.php",
    "src/modules/bookingfrontend/services/applications/CitizenHospitalityOrder.php",
    "src/modules/bookingfrontend/controllers/applications/HospitalityOrderController.php",
    "src/modules/booking/controllers/HospitalityOrderController.php",
] + [f"src/modules/{m}/setup/phpgw_{l}.lang" for m in ("booking", "bookingfrontend") for l in ("no", "nn", "en")]

HOA = "src/modules/booking/services/HospitalityOrderAmount.php"
REPO = "src/modules/booking/repositories/HospitalityOrderRepository.php"
ART = "src/modules/booking/repositories/HospitalityArticleRepository.php"
C = "src/modules/bookingfrontend/controllers/applications/HospitalityOrderController.php"
ADM = "src/modules/booking/controllers/HospitalityOrderController.php"
LANGF = "src/modules/bookingfrontend/setup/phpgw_{}.lang"
LANGB = "src/modules/booking/setup/phpgw_{}.lang"

CREATE_USE = "use ($applicationId, $hospitalityId, $locationResourceId, $body, $bookingUserId, $resolvedLines): int {"
UPDATE_OPEN = "$this->orderRepo->transactional(function () use ($orderId, $updateData, $bookingUserId, $resolvedLines): void {"
UPDATE_WRITE = ("\t\t\t\tif (!empty($updateData)) {\n\t\t\t\t\t$updateData['modified_by'] = $bookingUserId;\n"
                "\t\t\t\t\t$this->orderRepo->update($orderId, $updateData);\n\t\t\t\t}\n\n")
CREATE_LOOP = ("\t\t\t\tforeach ($resolvedLines as $lineData) {\n\t\t\t\t\t$lineData['order_id'] = $orderId;\n"
               "\t\t\t\t\t$this->orderRepo->addOrderLine($lineData);\n\t\t\t\t}\n\n\t\t\t\treturn $orderId;\n\t\t\t});\n")

# (id, description, [(file, old, new), ...])
MUTANTS = [
    # --- the rule (HospitalityOrderAmount) ---
    ("A1", "the line is compared as a float (PHP's 2463661 * 40.59 is 99999999.99000001: C4's legal line is refused)",
     [(HOA, "if (abs($price) > self::MAX_CENTS || abs($amount['net']) > self::MAX_CENTS) {",
       "if (abs($price) > self::MAX_CENTS || (float)$line['quantity'] * (float)$line['unit_price'] > 99999999.99) {")]),
    ("A2", "the VAT is a constant 25 % (0 % and 15 % lines judged at 25 %)",
     [(HOA, "$percent = (int)($taxPercents[(int)$taxCode] ?? 0);", "$percent = 25;")]),
    ("A3", "the order rule is on the NET (VAT left out)",
     [(HOA, "$sum += $amount['net'] + $amount['tax'];", "$sum += $amount['net'];")]),
    ("A4", "no order rule at all (per-line only)",
     [(HOA, "if ($grossAfter <= self::MAX_CENTS || $grossAfter <= abs(self::gross($before, $taxPercents))) {", "if (true) {")]),
    ("A5", "a strict order rule (an over-bound order can never be lowered)",
     [(HOA, "if ($grossAfter <= self::MAX_CENTS || $grossAfter <= abs(self::gross($before, $taxPercents))) {", "if ($grossAfter <= self::MAX_CENTS) {")]),
    ("A6", "øre are floored, not rounded half away from zero (half-øre VAT is lost)",
     [(HOA, "if ((abs($n) % $divisor) * 2 >= $divisor) {", "if (false) {")]),
    ("A7", "an unknown tax code is taken as 25 %, not billing's 0 %",
     [(HOA, "$percent = (int)($taxPercents[(int)$taxCode] ?? 0);", "$percent = (int)($taxPercents[(int)$taxCode] ?? 25);")]),
    ("A8", "a value's third decimal is truncated, not rounded as numeric(10,2) rounds it",
     [(HOA, "$hundredths = $head + ($next >= '5' ? 1 : 0);", "$hundredths = $head;")]),
    ("A9", "the unit price itself is not bounded (0.50 × 150,000,000.00 passes)",
     [(HOA, "if (abs($price) > self::MAX_CENTS || abs($amount['net']) > self::MAX_CENTS) {", "if (abs($amount['net']) > self::MAX_CENTS) {")]),
    ("A10", "the bound is one øre too high (100,000,000.00 passes)",
     [(HOA, "public const MAX_CENTS = 9999999999;", "public const MAX_CENTS = 10000000000;")]),
    ("A11", "the effective price is the BASE price, not the override (lane 6)",
     [(ART, "'effective_price' => $article['override_price'] ?? $article['base_price'],", "'effective_price' => $article['base_price'],")]),
    ("A12", "the bound is one øre too LOW (99,999,999.99, which numeric(10,2) stores, is refused: lane 14b's at-bound order)",
     [(HOA, "public const MAX_CENTS = 9999999999;", "public const MAX_CENTS = 9999999998;")]),
    # --- the applicant's controller ---
    ("C1", "createOrder judges nothing",
     [(C, "$amountRefusal = HospitalityOrderAmount::refusal($resolvedLines, $resolvedLines, [], $this->articleRepo->getTaxPercentByCode());", "$amountRefusal = null;")]),
    ("C2", "updateOrder judges nothing",
     [(C, "\n\t\t\t\tif ($amountRefusal !== null) {\n\t\t\t\t\treturn $this->refuseAmount($response, $amountRefusal);\n\t\t\t\t}\n", "\n")]),
    ("C3", "updateOrder saves the order's own fields BEFORE the line checks (base's order: a refused line leaves the comment)",
     [(C, "\t\t\t$resolvedLines = null;\n",
       "\t\t\tif (!empty($updateData)) {\n\t\t\t\t$this->orderRepo->update($orderId, $updateData + ['modified_by' => $bookingUserId]);\n\t\t\t}\n\t\t\t$resolvedLines = null;\n")]),
    ("C4", "updateOrder judges against no stored lines (lowering an over-bound order is refused)",
     [(C, "\t\t\t\t\t$this->orderRepo->getOrderLines($orderId),\n\t\t\t\t\t$this->articleRepo->getTaxPercentByCode()", "\t\t\t\t\t[],\n\t\t\t\t\t$this->articleRepo->getTaxPercentByCode()")]),
    ("C5", "the refusal is a 400, not a 422",
     [(C, "return ResponseHelper::sendErrorResponse($payload, 422, $response);", "return ResponseHelper::sendErrorResponse($payload, 400, $response);")]),
    ("C6", "the refusal text is not translated",
     [(C, "'error' => lang($refusal['code'] === HospitalityOrderAmount::CODE_LINE ? 'catering_amount_too_large' : 'catering_order_total_too_large'),", "'error' => 'Amount too large',")]),
    ("C7", "the refusal does not name the line",
     [(C, "\t\t\t$payload['line'] = $refusal['line'];\n", "")]),
    # --- the admin's controller ---
    ("D1", "addLine judges nothing",
     [(ADM, "$amountRefusal = $this->amountRefusal($orderId, $lineData, null);", "$amountRefusal = null;")]),
    ("D2", "updateLine judges nothing",
     [(ADM, "$amountRefusal = $this->amountRefusal($orderId, $asStored, $lineId);", "$amountRefusal = null;")]),
    ("D3", "updateLine judges the STORED unit price and tax code, not the body's",
     [(ADM, "$asStored[$field] = array_key_exists($field, $body) ? $body[$field] : ($oldLine[$field] ?? null);",
       "$asStored[$field] = $field === 'quantity' && array_key_exists($field, $body) ? $body[$field] : ($oldLine[$field] ?? null);")]),
    ("D4", "updateLine counts the order as it was (the edited line is not replaced in the total)",
     [(ADM, "$after[] = $lineId !== null && (int)$stored['id'] === $lineId ? $line : $stored;", "$after[] = $stored;")]),
    ("D5", "a comment-only edit is judged (a line already beyond the bound can no longer be commented on)",
     [(ADM, "if (array_key_exists('quantity', $body) || array_key_exists('unit_price', $body) || array_key_exists('tax_code', $body)) {", "if (true) {")]),
    ("D6", "the admin refusal is a 500",
     [(ADM, "return $response->withHeader('Content-Type', 'application/json')->withStatus(422);", "return $response->withHeader('Content-Type', 'application/json')->withStatus(500);")]),
    ("D7", "the admin refusal text is not translated",
     [(ADM, "$response->getBody()->write(json_encode(['error' => lang($key), 'code' => $refusal['code']]));", "$response->getBody()->write(json_encode(['error' => 'Amount too large', 'code' => $refusal['code']]));")]),
    # --- one transaction (run_pg.php) ---
    ("T1", "the rollback catches Exception only (a TypeError leaves the transaction open)",
     [(REPO, "} catch (\\Throwable $e) {\n            if ($own && $this->db->inTransaction()) {", "} catch (\\Exception $e) {\n            if ($own && $this->db->inTransaction()) {")]),
    ("T2", "no rollback",
     [(REPO, "                $this->db->rollBack();\n", "")]),
    ("T3", "a transaction is opened even inside a caller's ($own always true)",
     [(REPO, "$own = !$this->db->inTransaction();", "$own = true;")]),
    ("T4", "createOrder commits the order BEFORE its lines (the loop outside the transaction)",
     [(C, CREATE_LOOP,
       "\t\t\t\treturn $orderId;\n\t\t\t});\n\t\t\tforeach ($resolvedLines as $lineData) {\n\t\t\t\t$lineData['order_id'] = $orderId;\n\t\t\t\t$this->orderRepo->addOrderLine($lineData);\n\t\t\t}\n")]),
    ("T5", "createOrder without a transaction",
     [(C, "$orderId = $this->orderRepo->transactional(function () " + CREATE_USE, "$orderId = (function () " + CREATE_USE),
      (C, "\t\t\t\treturn $orderId;\n\t\t\t});\n", "\t\t\t\treturn $orderId;\n\t\t\t})();\n")]),
    ("T6", "updateOrder without a transaction",
     [(C, UPDATE_OPEN, "(function () use ($orderId, $updateData, $bookingUserId, $resolvedLines): void {"),
      (C, "\t\t\t\t}\n\t\t\t});\n\n\t\t\t$updatedOrder", "\t\t\t\t}\n\t\t\t})();\n\n\t\t\t$updatedOrder")]),
    ("T7", "updateOrder saves the order's own fields outside (before) the transaction",
     [(C, UPDATE_WRITE + "\t\t\t\tif ($resolvedLines !== null) {", "\t\t\t\tif ($resolvedLines !== null) {"),
      (C, "\t\t\t" + UPDATE_OPEN, "\t\t\tif (!empty($updateData)) {\n\t\t\t\t$this->orderRepo->update($orderId, $updateData + ['modified_by' => $bookingUserId]);\n\t\t\t\t$updateData = [];\n\t\t\t}\n\t\t\t" + UPDATE_OPEN)]),
    ("T8", "createOrder tells the officer INSIDE the transaction (before the commit)",
     [(C, CREATE_USE, CREATE_USE.replace("$resolvedLines)", "$resolvedLines, $application)")),
      (C, "\t\t\t\treturn $orderId;\n\t\t\t});\n",
       "\t\t\t\tif ($application['status'] !== HospitalityOrderGuard::DRAFT_STATUS) {\n"
       "\t\t\t\t\t$this->recordApplicantChange(CateringMailRenderer::KIND_ADDED, $application, null, $this->orderRepo->getOrderWithLines($orderId), $bookingUserId);\n"
       "\t\t\t\t}\n\t\t\t\treturn $orderId;\n\t\t\t});\n")]),
    ("T9", "the transaction is never committed",
     [(REPO, "            if ($own) {\n                $this->db->commit();\n            }\n", "")]),
    # --- the texts ---
    ("L1", "the applicant's Norwegian (bokmål) line text is gone",
     [(LANGF.format("no"), "catering_amount_too_large\tbookingfrontend\tno\t", "catering_amount_too_large_x\tbookingfrontend\tno\t")]),
    ("L2", "the officer's English total text is gone",
     [(LANGB.format("en"), "hospitality_order_total_too_large\tbooking\ten\t", "hospitality_order_total_too_large_x\tbooking\ten\t")]),
    ("L3", "the applicant's Nynorsk total text is worded as bokmål",
     [(LANGF.format("nn"), "Totalbeløpet for bestillinga er for stort.", "Totalbeløpet for bestillingen er for stort.")]),
]


def make_tree():
    tree = tempfile.mkdtemp(prefix="hd-amount-mut-")
    for rel in FILES:
        dst = os.path.join(tree, rel)
        os.makedirs(os.path.dirname(dst), exist_ok=True)
        shutil.copyfile(os.path.join(ROOT, rel), dst)
    return tree


def run(tree, script):
    env = dict(os.environ, HD_ROOT=tree, VENDOR_AUTOLOAD=os.path.join(ROOT, "vendor/autoload.php"))
    p = subprocess.run([PHP, os.path.join(HERE, script)], capture_output=True, text=True, env=env, timeout=600)
    summary = [l for l in p.stdout.splitlines() if l.startswith("Amount")]
    return p.returncode, (summary[-1] if summary else (p.stdout + p.stderr).strip()[-300:])


def main():
    base = make_tree()
    rc_h, out_h = run(base, "run.php")
    rc_p, out_p = run(base, "run_pg.php")
    shutil.rmtree(base)
    print(f"baseline run.php    rc={rc_h}  {out_h}")
    print(f"baseline run_pg.php rc={rc_p}  {out_p}")
    if rc_h != 0 or rc_p not in (0, 3):
        print("BASELINE NOT GREEN: no mutant can be judged")
        return 2
    pg = rc_p == 0
    caught = survived = bad = 0
    for mid, desc, edits in MUTANTS:
        tree = make_tree()
        try:
            ok = True
            for rel, old, new in edits:
                path = os.path.join(tree, rel)
                text = open(path, encoding="utf-8").read()
                n = text.count(old)
                if n != 1:
                    print(f"{mid:4}  BAD      the text to replace occurs {n} times in {rel}  {desc}")
                    ok = False
                    break
                open(path, "w", encoding="utf-8").write(text.replace(old, new))
            if not ok:
                bad += 1
                continue
            rc_h, out_h = run(tree, "run.php")
            rc_p, out_p = run(tree, "run_pg.php") if pg else (3, "skipped")
            if rc_h == 1 or rc_p == 1:
                caught += 1
                print(f"{mid:4}  caught   run.php rc={rc_h} pg rc={rc_p}  {desc}\n        {out_h} | {out_p}")
            elif rc_h not in (0, 1) or rc_p not in (0, 1, 3):
                bad += 1
                print(f"{mid:4}  CRASH    run.php rc={rc_h} pg rc={rc_p}  {desc}\n        {out_h} | {out_p}")
            else:
                survived += 1
                print(f"{mid:4}  SURVIVED run.php rc={rc_h} pg rc={rc_p}  {desc}")
        finally:
            shutil.rmtree(tree)
    print(f"\n{caught}/{len(MUTANTS)} caught, {survived} survived, {bad} bad" + ("" if pg else "  (run_pg.php SKIPPED: the T* mutants are not judged)"))
    if not pg:
        return 3
    return 0 if survived == 0 and bad == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
