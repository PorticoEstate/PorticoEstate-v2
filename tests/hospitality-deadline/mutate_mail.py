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
    "src/modules/booking/services/HospitalityDeadlineCalculator.php",
    "src/modules/booking/services/CateringText.php",
    "src/modules/booking/services/HospitalityOrderDiff.php",
    "src/modules/booking/services/CateringLangSwitch.php",
    "src/modules/booking/services/CateringMailRenderer.php",
    "src/modules/booking/html/base/emails/catering_changed_by_applicant.twig",
    "src/modules/bookingfrontend/services/applications/CateringChangeNotifier.php",
    "src/modules/bookingfrontend/controllers/applications/HospitalityOrderController.php",
] + [f"src/modules/{m}/setup/phpgw_{l}.lang" for m in ("booking", "bookingfrontend") for l in ("no", "nn", "en")]

R = "src/modules/booking/services/CateringMailRenderer.php"
T = "src/modules/booking/services/CateringText.php"
D = "src/modules/booking/services/HospitalityOrderDiff.php"
N = "src/modules/bookingfrontend/services/applications/CateringChangeNotifier.php"
TW = "src/modules/booking/html/base/emails/catering_changed_by_applicant.twig"

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
    ("Mm7", TW, "a changed value is output raw (a live <script> from the applicant)",
     "<td valign=\"top\">{{ row.to }}</td>", "<td valign=\"top\">{{ row.to|raw }}</td>"),
    ("Mm8", TW, "the actor name is output raw",
     "<p>{{ intro }}", "<p>{{ intro|raw }}"),
    ("Mm9", N, "the notifier's outer catch only catches Exception (the PHP 8 Error trap)",
     "        } catch (\\Throwable $e) {\n            error_log('Catering change notification failed: ' . $e->getMessage());",
     "        } catch (\\Exception $e) {\n            error_log('Catering change notification failed: ' . $e->getMessage());"),
    ("Mm10", N, "a failure of the deferred send is not contained",
     "                } catch (\\Throwable $e) {\n                    error_log(\"Catering change mail for application {$applicationId} failed: \" . $e->getMessage());\n                }",
     "                } finally {\n                }"),
    ("Mm11", N, "an application with no case officer still gets a mail",
     "            if ($officerId <= 0) {\n                return false;\n            }\n", ""),
    ("Mm12", N, "an officer with no address is not skipped",
     "            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {", "            if (false) {"),
    ("Mm13", N, "no language preference does not fall back to Norwegian",
     "            if (!in_array($lang, self::SUPPORTED_LANGS, true)) {\n                $lang = self::DEFAULT_LANG;\n            }\n", ""),
    ("Mm14", D, "an article name is not decoded in the diff",
     "$name = CateringText::decode($line['article_name'] ?? '');", "$name = (string)($line['article_name'] ?? '');"),
    ("Mm15", D, "the serving time is shown in UTC, not venue time",
     "return $instant->setTimezone(new \\DateTimeZone(self::VENUE_TIMEZONE))->format('d.m.Y H:i');", "return $instant->format('d.m.Y H:i');"),
    ("Mm16", D, "an unchanged field is reported as changed",
     "            if ($from === $to) {\n                continue;\n            }\n", ""),
    ("Mm17", D, "a new order shows a 'status' row",
     "            if ($before === null && $key === 'status') {\n                continue;\n            }\n", ""),
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
