#!/usr/bin/env python3
"""
Mutation check for the catering mail harness (run_mail.php, GH #1425).

Same idea as mutate.py: one deliberate defect at a time, applied to a TEMP COPY of the files the
harness loads (never to the tree), and the harness must go red for each. The baseline on the same
copy must be green first, and every replacement must match exactly once.

Usage:  python3 tests/hospitality-deadline/mutate_mail.py     (PHP_BIN overrides the binary)
"""
import os
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
PHP = os.environ.get("PHP_BIN", "php")

# Everything run_mail.php reads from HD_ROOT.
FILES = [
    "src/modules/phpgwapi/services/PreferencesReader.php",
    "src/modules/booking/services/HospitalityDeadlineCalculator.php",
    "src/modules/booking/services/CateringText.php",
    "src/modules/booking/services/HospitalityOrderDiff.php",
    "src/modules/booking/services/CateringLangSwitch.php",
    "src/modules/booking/services/TranslationLangSwitch.php",
    "src/modules/booking/repositories/HospitalityOrderRepository.php",
    "src/modules/booking/services/CateringMailRenderer.php",
    "src/modules/booking/html/base/emails/catering_changed_by_applicant.twig",
    "src/modules/bookingfrontend/services/applications/CateringChangeNotifier.php",
    "src/modules/bookingfrontend/controllers/applications/HospitalityOrderController.php",
    "src/modules/booking/services/CateringChangeText.php",
    "src/modules/booking/controllers/HospitalityOrderController.php",
    "src/modules/bookingfrontend/services/AllocationTimeChangeNotifier.php",
    "src/modules/booking/controllers/ApplicationController.php",
    "src/modules/booking/html/base/application/show/application_show.js",
    "src/modules/booking/templates/base/mail_settings.xsl",
] + [f"src/modules/{m}/setup/phpgw_{l}.lang" for m in ("booking", "bookingfrontend") for l in ("no", "nn", "en")]

R = "src/modules/booking/services/CateringMailRenderer.php"
T = "src/modules/booking/services/CateringText.php"
D = "src/modules/booking/services/HospitalityOrderDiff.php"
N = "src/modules/bookingfrontend/services/applications/CateringChangeNotifier.php"
TW = "src/modules/booking/html/base/emails/catering_changed_by_applicant.twig"
C = "src/modules/bookingfrontend/controllers/applications/HospitalityOrderController.php"
REPO = "src/modules/booking/repositories/HospitalityOrderRepository.php"
CT = "src/modules/booking/services/CateringChangeText.php"
AC = "src/modules/booking/controllers/HospitalityOrderController.php"
APPC = "src/modules/booking/controllers/ApplicationController.php"
SHOWJS = "src/modules/booking/html/base/application/show/application_show.js"
XSL = "src/modules/booking/templates/base/mail_settings.xsl"

# (id, file, description, old, new)
MUTATIONS = [
    ("Mm1", R, "the acting language is not restored after the render",
     "        } finally {\n            $this->lang->set($saved);\n        }", "        } finally {\n        }"),
    ("Mm2", R, "the officer language is never forced (the citizen's cookie wins)",
     "            $this->lang->set($officerLang);\n", ""),
    ("Mm3", R, "the event name is not decoded",
     "$event = CateringText::decode($app['name'] ?? '');", "$event = (string)($app['name'] ?? '');"),
    ("Mm4", T, "the subject is HTML-escaped",
     "        return trim($text);\n    }\n}", "        return htmlspecialchars(trim($text));\n    }\n}"),
    ("Mm5", T, "CR/LF survives into the subject (header injection)",
     "$text = preg_replace('/[\\r\\n]+/', ' ', $text) ?? $text;", "$text = $text;"),
    ("Mm6", T, "the second decode pass for double-encoded names is dropped",
     "        if (strpos($decoded, '&amp;') !== false) {\n            $decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');\n        }\n",
     ""),
    ("Mm7", TW, "a line of the change is output raw (a live <script> from the applicant)",
     "<li>{{ line }}</li>", "<li>{{ line|raw }}</li>"),
    ("Mm8", TW, "the actor name is output raw",
     "<p>{{ intro }}", "<p>{{ intro|raw }}"),
    ("Mm9", N, "the notifier's outer catch only catches Exception (the PHP 8 Error trap)",
     "        } catch (\\Throwable $e) {\n            error_log('Catering change notification failed: ' . $e->getMessage());",
     "        } catch (\\Exception $e) {\n            error_log('Catering change notification failed: ' . $e->getMessage());"),
    ("Mm10", N, "a failure of the deferred send is not contained",
     "                    } catch (\\Throwable $e) {\n                        error_log(\"Catering change mail for application {$applicationId} failed: \" . $e->getMessage());\n                    }",
     "                    } finally {\n                    }"),
    ("Mm11", N, "a case officer WITH an address is not the only recipient (the group is asked too)",
     "            if ($email !== null) {\n                return [['account_id' => $officerId",
     "            if ($email === 'never') {\n                return [['account_id' => $officerId"),
    ("Mm12", N, "an unusable address is accepted",
     "return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;",
     "return $email !== '' ? $email : null;"),
    ("Mm13", N, "no language preference does not fall back to Norwegian",
     "return in_array($lang, self::SUPPORTED_LANGS, true) ? $lang : self::DEFAULT_LANG;", "return $lang;"),
    ("Mm14", D, "an article name is not decoded in the diff",
     "$name = CateringText::decode($line['article_name'] ?? '');", "$name = (string)($line['article_name'] ?? '');"),
    ("Mm15", D, "the serving time is shown in UTC, not venue time",
     "return $instant->setTimezone(new \\DateTimeZone(self::VENUE_TIMEZONE))->format('d.m.Y H:i');", "return $instant->format('d.m.Y H:i');"),
    ("Mm16", D, "an unchanged field is reported as changed",
     "            if ($from === $to) {\n                continue;\n            }\n", ""),
    ("Mm18", C, "the case entry's catch only catches Exception: a TypeError from the changelog escapes the write path",
     "\t\t} catch (\\Throwable $e) {\n\t\t\terror_log(\"Catering case entry failed",
     "\t\t} catch (\\Exception $e) {\n\t\t\terror_log(\"Catering case entry failed"),
    ("Mm19", C, "the notification's catch only catches Exception: a TypeError from notify() escapes the write path",
     "\t\t} catch (\\Throwable $e) {\n\t\t\terror_log(\"Catering change notification failed for order",
     "\t\t} catch (\\Exception $e) {\n\t\t\terror_log(\"Catering change notification failed for order"),
    ("Mm20", C, "a PUT that changed nothing still leaves an entry and mails the officer",
     "\t\t\tif ($kind === CateringMailRenderer::KIND_CHANGED && HospitalityOrderDiff::isEmpty($diff)) {\n\t\t\t\treturn;\n\t\t\t}\n", ""),
    ("Mm21", C, "an entry is written even with no booking user (the table requires an author)",
     "\t\t\tif ($bookingUserId) {\n\t\t\t\t$this->orderRepo->addChangelogEntry([\n\t\t\t\t\t'order_id' => (int)$after['id'],",
     "\t\t\tif (true) {\n\t\t\t\t$this->orderRepo->addChangelogEntry([\n\t\t\t\t\t'order_id' => (int)$after['id'],"),
    ("Mm22", C, "the controller no longer builds the officer-locale notifier",
     "$this->notifier = new CateringChangeNotifier();", "$this->notifier = new class extends CateringChangeNotifier {};"),
    ("Mm23", N, "the notifier's default renderer is not on the real Translation switch",
     "$this->renderer = $renderer ?? new CateringMailRenderer(new TranslationLangSwitch());",
     "$this->renderer = $renderer ?? new CateringMailRenderer(new class implements \\App\\modules\\booking\\services\\CateringLangSwitch { public function current(): string { return 'no'; } public function set(string $lang): void {} });"),
    ("Mm24", REPO, "a case entry's time is handed to the browser as stored (naive UTC), one hour early",
     "                ->setTimezone(new \\DateTimeZone('Europe/Oslo'))\n                ->format('c');",
     "                ->setTimezone(new \\DateTimeZone('UTC'))\n                ->format('c');"),
    ("Mm25", REPO, "the order page's changelog no longer converts changed_at",
     "            $row['changed_at'] = self::utcToVenueIso($row['changed_at']);\n            if ($row['old_value']) {",
     "            if ($row['old_value']) {"),
    ("Mm26", REPO, "the application list's marker no longer converts changed_at",
     "$out[(int)$row['order_id']] = self::utcToVenueIso($row['changed_at']);", "$out[(int)$row['order_id']] = $row['changed_at'];"),
    ("Mm27", C, "the Diff is called outside any try: a TypeError from it turns a committed write into a 500",
     "\t\ttry {\n\t\t\t$diff = HospitalityOrderDiff::between($before, $after);\n\t\t\t// A PUT that resends the values it already had changed nothing: no entry, no mail.\n\t\t\tif ($kind === CateringMailRenderer::KIND_CHANGED && HospitalityOrderDiff::isEmpty($diff)) {\n\t\t\t\treturn;\n\t\t\t}\n\t\t} catch (\\Throwable $e) {\n\t\t\terror_log(\"Catering change diff failed for order {$after['id']}: \" . $e->getMessage());\n\t\t\treturn;\n\t\t}\n",
     "\t\t$diff = HospitalityOrderDiff::between($before, $after);\n\t\t// A PUT that resends the values it already had changed nothing: no entry, no mail.\n\t\tif ($kind === CateringMailRenderer::KIND_CHANGED && HospitalityOrderDiff::isEmpty($diff)) {\n\t\t\treturn;\n\t\t}\n"),
    ("Mm28", C, "the Diff's catch only catches Exception: a TypeError from it escapes the write path",
     "\t\t} catch (\\Throwable $e) {\n\t\t\terror_log(\"Catering change diff failed",
     "\t\t} catch (\\Exception $e) {\n\t\t\terror_log(\"Catering change diff failed"),
    ("Mm17", D, "a new order shows a 'status' row",
     "            if ($before === null && $key === 'status') {\n                continue;\n            }\n", ""),
    # special_requirements: the applicant's note to the kitchen. None of these was caught before the harness
    # drove that field through the diff, the summary and the template.
    ("Mm29", TW, "the log's heading is output raw (the applicant's name as live HTML)",
     "<b>{{ log.heading }}</b>", "<b>{{ log.heading|raw }}</b>"),
    ("Mm30", TW, "the order line of the log is output raw",
     "<br />{{ log.context }}", "<br />{{ log.context|raw }}"),
    ("Mm31", D, "the diff drops special_requirements: the officer is never told about the note",
     "        $out['special_requirements'] = ['value' => CateringText::decode($order['special_requirements'] ?? ''), 'label' => null];\n", ""),
    ("Mm32", CT, "special_requirements is not a known field: its line is labelled with the raw key",
     "['hospitality', 'serving_time', 'location', 'comment', 'special_requirements', 'total'], true)) {\n            return lang('booking.catering_field_' . $key);",
     "['hospitality', 'serving_time', 'location', 'comment', 'total'], true)) {\n            return lang('booking.catering_field_' . $key);"),
    # ---- GH #1425 build (task 47036), P1: S1 «Sist endret», S2 readable log incl. VAT per line, S3 sort ----
    ("Mp1", D, "a line's known VAT rate is not stored with it",
     "        if ($vat !== null) {\n            $incl = self::money(", "        if (false) {\n            $incl = self::money("),
    ("Mp2", D, "the incl. amount assumes 25 % instead of the line's own rate",
     "return $exOre + (int)round($exOre * $percent / 100);", "return $exOre + (int)round($exOre * 25 / 100);"),
    ("Mp2b", D, "the VAT is rounded half-to-even (banker's), not half away from zero as billing's numeric(10,2) cast",
     "return $exOre + (int)round($exOre * $percent / 100);", "return $exOre + (int)round($exOre * $percent / 100, 0, PHP_ROUND_HALF_EVEN);"),
    ("Mp3", D, "an unknown rate (no fm_ecomva row) is taken for 0 %",
     "        if ($percent === null || $percent === '' || !is_numeric($percent)) {\n            return null;\n        }",
     "        if ($percent === null || $percent === '' || !is_numeric($percent)) {\n            return '0';\n        }"),
    ("Mp4", D, "the total claims an incl. amount although a line's rate is unknown",
     "            if ($vat === null) {\n                return $text;\n            }", "            if ($vat === null) {\n                continue;\n            }"),
    ("Mp4b", D, "the incl. total rounds the VAT once on the total instead of once per line (billing rounds per line)",
     "            $inclOre += self::lineInclOre(self::lineAmount($line), (float)$vat);",
     "            $inclOre += self::lineAmount($line) * 100 * (100 + (float)$vat) / 100;"),
    ("Mp5", D, "parseLine drops a line's comment",
     "'comment' => (string)($m['comment'] ?? ''),", "'comment' => '',"),
    ("Mp6", CT, "a line with no known rate is labelled «inkl. mva»",
     "            $basis = lang('booking.catering_log_excl_vat');", "            $basis = lang('booking.catering_log_incl_vat');"),
    ("Mp7", CT, "the date of a time is taken in UTC, not venue time",
     "self::VENUE_TIMEZONE, \\IntlDateFormatter::GREGORIAN, $pattern", "'UTC', \\IntlDateFormatter::GREGORIAN, $pattern"),
    ("Mp7b", CT, "the clock time is shown in UTC, not venue time",
     "self::VENUE_TIMEZONE, \\IntlDateFormatter::GREGORIAN, 'HH:mm'", "'UTC', \\IntlDateFormatter::GREGORIAN, 'HH:mm'"),
    ("Mp8", CT, "thousands are not grouped («1600 kr»)",
     "number_format($ore / 100, $decimals, ',', \"\\u{00A0}\")", "number_format($ore / 100, $decimals, ',', '')"),
    ("Mp9", CT, "the unit is not translated («each»)",
     "            $unit = lang('booking.unit_' . $unitKey);", "            $unit = $unit;"),
    ("Mp10", CT, "the kind is not read from the stored comment",
     "        if (is_string($kind)) {\n            return $kind;\n        }\n", ""),
    ("Mp11", CT, "a change shows the new value on both sides",
     "                    self::valueText($key, $old[$key] ?? '', $lang),", "                    self::valueText($key, $new[$key] ?? '', $lang),"),
    ("Mp12", CT, "an addition is described by the order as it is now, not as it was added",
     "        $own = $kind === self::KIND_CHANGED ? [] : $new;\n        $contextParts = [];", "        $own = [];\n        $contextParts = [];"),
    ("Mp13", AC, "the admin list is sorted latest serving first",
     "return [$sa, (int)$a['id']] <=> [$sb, (int)$b['id']];", "return [$sb, (int)$a['id']] <=> [$sa, (int)$b['id']];"),
    ("Mp14", AC, "the admin index does not sort",
     "\t\t\t\t$orders = self::sortByServingTime($orders);\n", ""),
    ("Mp15", AC, "an officer's own entry is also put into words",
     "\t\t\tif (($entry['change_type'] ?? null) !== 'applicant_change') {\n\t\t\t\tcontinue;\n\t\t\t}\n", ""),
    ("Mp15b", AC, "the admin order page's language lookup is outside any try: an Error from it is a 500",
     "\t\t} catch (\\Throwable $e) {\n\t\t\terror_log('Catering changelog left unformatted", "\t\t} catch (\\Exception $e) {\n\t\t\terror_log('Catering changelog left unformatted"),
    ("Mp16", D, "the stored incl. amount is left out of the line value",
     "            $text .= \" [VAT {$vat}% incl {$incl}]\";", "            $text .= \" [VAT {$vat}%]\";"),
    ("Mp17", CT, "the incl. amount stored with an entry is ignored and worked out again",
     "            $amountOre = $p['incl'] !== null\n", "            $amountOre = false\n"),
    # Guard: «Sist endret» is NOT moved by a catering change (operator 2026-11-23). A write of bb_application put
    # into the paths a catering change takes must turn the harness red.
    ("Mg1", REPO, "the order repository writes bb_application (e.g. a «Sist endret» bump)",
     "        $sql = \"UPDATE bb_hospitality_order\n                SET status = :status, modified = DEFAULT, modified_by = :modified_by\n                WHERE id = :id\";",
     "        $sql = \"UPDATE bb_application\n                SET status = :status, modified = DEFAULT, modified_by = :modified_by\n                WHERE id = :id\";"),
    ("Mg2", C, "the applicant path writes bb_application",
     "\t\t$stmt = \\App\\Database\\Db::getInstance()->prepare(\"SELECT name FROM bb_activity WHERE id = :id\");",
     "\t\t$stmt = \\App\\Database\\Db::getInstance()->prepare(\"UPDATE bb_application SET modified = NOW() WHERE id = :id\");"),
    # ---- P2: S4 Historikk (read-side), S5 mail: deadline, D1 group, D4 switch, dedup, identity ----
    ("Mq1", N, "the switch is ignored",
     "            if (!$this->notificationsEnabled()) {\n                return false;\n            }\n", ""),
    ("Mq2", N, "an absent switch counts as OFF",
     "return $stored === null || trim($stored) !== '0';", "return $stored !== null && trim($stored) !== '0';"),
    ("Mq3", N, "a case officer without an address does not fall back to the building's case officers",
     "            error_log(\"Catering change mail: case officer {$officerId} of application {$applicationId} has no usable email address; mailing the building's case officers instead\");",
     "            return [];"),
    ("Mq4", N, "two accounts with one address get two mails",
     "if ($email === null || isset($seen[strtolower($email)])) {", "if ($email === null) {"),
    ("Mq5", N, "the same address in another case counts as another address",
     "$seen[strtolower($email)] = true;", "$seen[$email] = true;"),
    ("Mq6", N, "every case officer of the building gets Norwegian, whatever their own language",
     "$out[] = ['account_id' => $id, 'email' => $email, 'lang' => self::language($common['lang'] ?? null)];",
     "$out[] = ['account_id' => $id, 'email' => $email, 'lang' => self::DEFAULT_LANG];"),
    ("Mq7", N, "the building's case officers are read through the Preferences singleton (the citizen becomes an officer)",
     "        $rows = $this->readCommonPreferenceRows($ids);\n",
     "        $rows = $this->readCommonPreferenceRows($ids);\n        foreach ($ids as $singletonId) { CreateObject('phpgwapi.preferences', $singletonId)->read(); }\n"),
    ("Mq8", N, "one recipient's mail failing to compose stops the others",
     "                    error_log(\"Catering change mail to account {$recipient['account_id']} for application {$applicationId} not composed: \" . $e->getMessage());\n                    continue;",
     "                    error_log(\"Catering change mail to account {$recipient['account_id']} for application {$applicationId} not composed: \" . $e->getMessage());\n                    return false;"),
    ("Mq9", N, "the officer path is the only path: no officer, no mail (the old ruling (d))",
     "        if ($buildingId <= 0) {", "        if (true) {"),
    ("Mq10", R, "the deadline's date is written in English whatever the officer's language",
     "$deadline = CateringChangeText::deadlineLine(new \\DateTimeImmutable($until), $lang);",
     "$deadline = CateringChangeText::deadlineLine(new \\DateTimeImmutable($until), 'en');"),
    ("Mq11", R, "a cancellation still carries a cancellation deadline",
     "if ($kind !== self::KIND_CANCELLED && $until !== null && $until !== '') {", "if ($until !== null && $until !== '') {"),
    ("Mq12", R, "the mail body is not the Endringslogg's wording (the change is left out)",
     "            'log' => $log,\n", "            'log' => ['heading' => '', 'context' => '', 'lines' => []],\n"),
    ("Mq13", TW, "the deadline line is not in the mail",
     "{% if deadline %}", "{% if false %}"),
    ("Mq14", TW, "the deadline line is output raw",
     "<p><b>{{ deadline }}</b></p>", "<p><b>{{ deadline|raw }}</b></p>"),
    ("Mq15", C, "the controller does not hand the notifier the order's cancellation deadline",
     "'cancel_deadline' => $kind === CateringMailRenderer::KIND_CANCELLED ? null : $this->cancelDeadline($application, $after),",
     "'cancel_deadline' => null,"),
    ("Mq16", C, "the controller does not hand the notifier the building (D1 can never reach the group)",
     "'building_id' => (int)($application['building_id'] ?? 0),", "'building_id' => 0,"),
    ("Mq17", CT, "the Historikk line is not tagged «Bevertning»",
     "'type_label' => lang('booking.catering_history_tag'),", "'type_label' => 'catering',"),
    ("Mq18", CT, "the Historikk lists oldest first",
     "$at($b) <=> $at($a)", "$at($a) <=> $at($b)"),
    ("Mq19", CT, "the Historikk line uses the order as it is now for an addition, not as it was added",
     "        $own = $kind === self::KIND_CHANGED ? [] : $new;\n        $hospitality =", "        $own = [];\n        $hospitality ="),
    ("Mq20", APPC, "the admin Historikk does not show the catering lines",
     "sendJSONResponse($this->withCateringHistory($id, $comments), 200, $response)", "sendJSONResponse($comments, 200, $response)"),
    ("Mq21", APPC, "the Historikk merge catches Exception only: an Error from it is a 500 on the case page",
     "\t\t} catch (\\Throwable $e) {\n\t\t\terror_log(\"Catering history left out", "\t\t} catch (\\Exception $e) {\n\t\t\terror_log(\"Catering history left out"),
    ("Mq22", REPO, "the Historikk's reader hands changed_at on as stored (naive UTC)",
     "            $row['changed_at'] = self::utcToVenueIso($row['changed_at']);\n            $row['old_value'] = $row['old_value'] ? json_decode",
     "            $row['old_value'] = $row['old_value'] ? json_decode"),
    ("Mq23", SHOWJS, "the Historikk shows the raw type, not the translated tag",
     "esc(comment.type_label || comment.type)", "esc(comment.type)"),
    ("Mq24", XSL, "the mail settings box is unticked when the key is absent (absent would read as OFF)",
     "not(normalize-space(config_data/notify_case_officer_on_catering_change) = '0')",
     "normalize-space(config_data/notify_case_officer_on_catering_change) = '1'"),
    ("Mq25", XSL, "an unticked box posts nothing (the key is unset, and absent = ON: OFF can never be stored)",
     "<input name=\"notify_case_officer_on_catering_change\" type=\"hidden\" value=\"0\"/>\n", ""),
]


def run_harness(tree):
    env = dict(os.environ)
    env["HD_ROOT"] = tree
    env["VENDOR_AUTOLOAD"] = os.path.join(ROOT, "vendor/autoload.php")
    p = subprocess.run([PHP, os.path.join(HERE, "run_mail.php")], env=env, capture_output=True, text=True)
    out = (p.stdout + p.stderr).strip().splitlines()
    return p.returncode, (out[-1] if out else "")


def main():
    tmp = tempfile.mkdtemp(prefix="hd-mutmail-")
    try:
        tree = os.path.join(tmp, "tree")
        for rel in FILES:
            dst = os.path.join(tree, rel)
            os.makedirs(os.path.dirname(dst), exist_ok=True)
            shutil.copyfile(os.path.join(ROOT, rel), dst)

        rc, tail = run_harness(tree)
        print(f"baseline rc={rc}  {tail}")
        if rc != 0:
            print("BASELINE IS RED - mutation results would mean nothing")
            return 1

        survived = []
        for mid, rel, desc, old, new in MUTATIONS:
            path = os.path.join(tree, rel)
            original = open(path, encoding="utf-8").read()
            n = original.count(old)
            if n != 1:
                print(f"{mid}: MUTATION DID NOT APPLY - pattern matched {n} times (need exactly 1): {desc}")
                return 1
            open(path, "w", encoding="utf-8").write(original.replace(old, new, 1))
            try:
                rc, tail = run_harness(tree)
            finally:
                open(path, "w", encoding="utf-8").write(original)
            caught = rc == 1
            print(f"{mid:5} {'caught  ' if caught else 'SURVIVED'} rc={rc}  {desc}")
            if not caught:
                survived.append(mid)

        if survived:
            print("SURVIVING MUTATIONS:", ", ".join(survived))
            return 1
        print(f"all {len(MUTATIONS)} mutations caught; baseline green")
        return 0
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    sys.exit(main())
