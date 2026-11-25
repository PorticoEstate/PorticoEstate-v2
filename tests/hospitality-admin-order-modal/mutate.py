#!/usr/bin/env python3
"""
Mutation check for run.mts (the admin «Opprett bestilling» dialog, task 49612).

A harness that never finishes (HANG) is red too: the run is killed by a timeout and counted.

One deliberate defect at a time is applied to a TEMP COPY of the files the harness reads (never to the tree) and the harness
is pointed at it with HM_ROOT; it must go red for each, on the rows the defect is about. The baseline on the same copy must
be green first, and every replacement must match exactly once. A final arm runs the harness on the tree as it was on base
(7891c67b6) and prints which rows are FIX rows (red there) and which are controls (green there).

Usage:  LUXON_DIR=<path to luxon> python3 tests/hospitality-admin-order-modal/mutate.py [--no-base]
        HM_BASE_SHA=775c8d0eb ...   runs the last arm against that commit instead (the parent of the 49771 fix-up: the L9/L10 rows are red there)

LUXON_DIR is needed by row L7 (the citizen client's own rule, run through luxon, is the oracle); without it the baseline
is red and the script says so rather than skipping the row.
"""
import os
import re
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
NODE = os.environ.get("NODE_BIN", "node")
HARNESS = os.path.join(HERE, "run.mts")
BASE = os.environ.get("HM_BASE_SHA", "7891c67b6")

BOOKING = "src/modules/booking"
MODAL = f"{BOOKING}/html/base/components/hospitality_order_modal/hospitality_order_modal.js"
APP_JS = f"{BOOKING}/html/base/application/show/application_show.js"
HOSP_JS = f"{BOOKING}/html/base/hospitality/show/hospitality_show.js"
APP_TWIG = f"{BOOKING}/html/base/_shared/application_show_body.twig"
HOSP_TWIG = f"{BOOKING}/html/base/hospitality/show/hospitality_show.twig"
LANG_NN = f"{BOOKING}/setup/phpgw_nn.lang"
FILES = [
    MODAL,
    f"{BOOKING}/html/base/components/search_select/search_select.js",
    APP_TWIG, HOSP_TWIG, APP_JS, HOSP_JS,
    f"{BOOKING}/repositories/HospitalityRepository.php",
    f"{BOOKING}/setup/phpgw_no.lang", LANG_NN, f"{BOOKING}/setup/phpgw_en.lang",
    "src/modules/bookingfrontend/client/src/service/api/api-utils.ts",
    "src/modules/bookingfrontend/client/src/service/utils/business-hours.ts",
    "src/modules/bookingfrontend/client/src/components/checkout/hospitality/hospitality-order-modal.tsx",
]

# (id, file, what, old, new, the row groups that MUST go red)
MUTANTS = [
    ("M1", MODAL, "the main arm is dropped from the location rule (the app filter alone, as on base)",
     "return loc.location_type === 'main' || !!_appResourceIds[loc.id];", "return !!_appResourceIds[loc.id];", {"L2"}),
    ("M2", MODAL, "the application filter is dropped from the location rule (every delivery location offered)",
     "return loc.location_type === 'main' || !!_appResourceIds[loc.id];", "return true;", {"L3"}),
    ("M3", MODAL, "the hours bound is dropped (the on-site list is the application's own range)",
     "return slots.filter(function (slot) {\n\t\t\t\t\treturn isWithinBusinessHours(slot, kitchen, hours.seasons, hours.closeWhenNoSeasons);\n\t\t\t\t});",
     "return slots;", {"L5", "L7"}),
    ("M4", MODAL, "SITE 1 only (the dates arrive after the locations): the filtered list is not set, the full one is",
     "locSelector.setItems(_allLocations.filter(isOfferedLocation));", "locSelector.setItems(_allLocations);", {"L3"}),
    ("M5", MODAL, "SITE 2 only (the locations arrive after the dates): the response is not filtered",
     "locations = locations.filter(isOfferedLocation);", "", {"L3"}),
    ("M6", MODAL, "the Lokale picker's empty text goes back to «Ingen bestillinger»",
     "emptyText: lang('noDeliveryLocations'),", "emptyText: lang('noOrders'),", {"L4"}),
    ("M7", MODAL, "every chosen location counts as the kitchen room (a remote room is bounded too)",
     "_selectedLocIsMain = !!item && item.location_type === 'main';", "_selectedLocIsMain = true;", {"L6"}),
    ("M8", MODAL, "a hospitality whose hours cannot be judged offers NO time (fails CLOSED; pe-ceo 49668 ruled fail-open)",
     "if (!_currentHospMainResourceId || !_currentHospBuildingId || !seasonsBaseUrl || !serverSettingsUrl) return slots;",
     "if (!_currentHospMainResourceId || !_currentHospBuildingId || !seasonsBaseUrl || !serverSettingsUrl) return [];", {"L5"}),
    ("M9", MODAL, "Sunday is weekday 0 instead of 7 (the ISO weekday luxon gives the citizen rule)",
     "var weekday = date.getDay() === 0 ? 7 : date.getDay();", "var weekday = date.getDay();", {"L7"}),
    ("M10", MODAL, "the boundary's weekday is not compared (every boundary applies every day)",
     "if (b.wday === weekday) dayBoundaries.push(b);", "dayBoundaries.push(b);", {"L7"}),
    ("M11", MODAL, "an inactive season counts",
     "return season.active && at >=", "return at >=", {"L7"}),
    ("M12", MODAL, "a season of ANOTHER resource counts (the resource match is dropped)",
     "var hasMatchingResources = resourceIds.length === 0 ||", "var hasMatchingResources = true ||", {"L7"}),
    ("M13", MODAL, "a season's end is not honoured (the season that ends mid-day Saturday stays open)",
     "at <= Date.parse(season.to_)", "true", {"L7"}),
    ("M14", MODAL, "an answer that is not a list reads as «no seasons» (so close_calendar_without_season closes the kitchen on a broken answer)",
     "if (!Array.isArray(res[0])) throw new Error('seasons');", "if (!Array.isArray(res[0])) res[0] = [];", {"L5"}),
    ("M15", MODAL, "the chosen-room flag survives a change of hospitality (a stale «main» bounds the new one)",
     "_selectedLocId = null;\n\t\t\t\t\t\t_selectedLocIsMain = false;", "_selectedLocId = null;", {"L6"}),
    ("M16", MODAL, "a late answer re-fills the list whatever the user did meanwhile",
     "if (waiting) populateTimeSlots(dateSelect.value);", "populateTimeSlots(dateSelect.value);", {"L5"}),
    ("M17", MODAL, "the hours are fetched on open instead of on the first need",
     "var _awaitingHours = false;     // the time list is empty until the hours arrive",
     "var _awaitingHours = false;     // the time list is empty until the hours arrive\n\t\tsetTimeout(function () { if (seasonsBaseUrl && hospBuildingId) requestKitchenHours(hospBuildingId); }, 0);", {"L5"}),
    ("M23", MODAL, "a FAILED seasons fetch leaves no time (fails CLOSED after the fetch; pe-ceo 49668 ruled fail-open)",
     "if (hours === false) return slots;", "if (hours === false) return [];", {"L5"}),
    ("M24", MODAL, "a failed SETTINGS request takes the seasons' bound with it (the citizen reads a missing setting as false and still bounds)",
     "fetchJson(serverSettingsUrl + '?include_configs=true').catch(function () { return null; })", "fetchJson(serverSettingsUrl + '?include_configs=true')", {"L5"}),
    ("M25", MODAL, "a failed seasons fetch is not said (no toast)",
     "opts.showToast(lang('openingHoursUnavailable'), 'danger');", "", {"L5"}),
    ("M26", MODAL, "a failed fetch is not remembered: the dialog asks again by itself, for ever (the run is killed by its timeout)",
     "_hoursByBuilding[key] = false;", "", {"HANG", "L5"}),
    ("M18", APP_TWIG, "the application page loses the new text's attribute",
     "\t data-lang-no-delivery-locations=\"{{ lang('booking.no_delivery_locations') }}\"\n", "", {"L4", "S1"}),
    ("M19", HOSP_TWIG, "the hospitality page loses the new text's attribute",
     "\t data-lang-no-delivery-locations=\"{{ lang('booking.no_delivery_locations') }}\"\n", "", {"L4", "S2"}),
    ("M20", LANG_NN, "the nn row of the new key is missing",
     "\nno_delivery_locations\tbooking\tnn\tIngen tilgjengelege lokale for denne bevertninga", "", {"S3"}),
    ("M21", HOSP_JS, "the hospitality page stops passing the kitchen's building to the dialog",
     "hospBuildingId: hospitalityData ? hospitalityData.resource_building_id : null,", "", {"S5"}),
    ("M22", APP_JS, "the application page stops passing the hours' URLs to the dialog",
     "seasonsBaseUrl: seasonsBaseUrl,\n\t\t\tserverSettingsUrl: serverSettingsUrl,", "", {"S5"}),
    ("M27", MODAL, "the failure toast shows a raw message again (the dialog's own «seasons» after «Feil:», as on 11e47556c)",
     "opts.showToast(lang('openingHoursUnavailable'), 'danger');",
     "opts.showToast(lang('error') + ': seasons', 'danger');", {"L5"}),
    ("M28", APP_TWIG, "the application page loses the failure text's attribute (the toast would show the raw key)",
     "\t data-lang-opening-hours-unavailable=\"{{ lang('booking.opening_hours_unavailable') }}\"\n", "", {"L5", "S1"}),
    ("M29", HOSP_TWIG, "the hospitality page loses the failure text's attribute",
     "\t data-lang-opening-hours-unavailable=\"{{ lang('booking.opening_hours_unavailable') }}\"\n", "", {"L5", "S2"}),
    ("M30", LANG_NN, "the nn row of the failure text's key is missing",
     "\nopening_hours_unavailable\tbooking\tnn\tKunne ikkje hente opningstidene – alle tider blir viste.", "", {"S3"}),
    # --- the fix-up on 775c8d0eb (task 49771): Opus final Q2 (the stale Lokale list) and Q3b (malformed season elements) ---
    ("M31", MODAL, "Q2: the cached Lokale list is not dropped when the hospitality changes (the new picker is handed the old list)",
     "\t\t\t\t\t\t_allLocations = null;\n", "", {"L9"}),
    ("M32", MODAL, "Q2: a picker made for the PREVIOUS hospitality may still write the cache when its late answer lands",
     "if (picker === locSelector) _allLocations = locations.slice();", "_allLocations = locations.slice();", {"L9"}),
    ("M33", MODAL, "Q3b: the try/catch is dropped (the catch rethrows: a malformed season throws out of the filter, as on 775c8d0eb)",
     "} catch (error) {", "} catch (error) {\n\t\t\t\tthrow error;", {"L10"}),
    ("M34", MODAL, "Q3b: a malformed answer fails CLOSED (no time) instead of open",
     "dropKitchenHours(key);\n\t\t\t\treturn slots;", "dropKitchenHours(key);\n\t\t\t\treturn [];", {"L10"}),
    ("M35", MODAL, "Q3b: a malformed answer is swallowed without a trace in the console",
     "console.error('HospitalityOrderModal: the kitchen\\'s opening hours could not be applied', error);\n\t\t\t\t", "", {"L10"}),
]


def run(tree):
    env = dict(os.environ)
    env["HM_ROOT"] = tree
    try:
        p = subprocess.run([NODE, HARNESS], env=env, capture_output=True, text=True, timeout=int(os.environ.get("HM_TIMEOUT", "90")))
    except subprocess.TimeoutExpired:
        return 124, "killed by the timeout: the dialog never settled", ["FAIL HANG the harness did not finish"], {"HANG"}
    out = (p.stdout + p.stderr).strip().splitlines()
    fails = [l for l in out if l.startswith("FAIL")]
    groups = set()
    for l in fails:
        m = re.match(r"FAIL ([LS]\d+)", l)
        if m:
            groups.add(m.group(1))
    return p.returncode, (out[-1] if out else ""), fails, groups


def fresh_tree():
    tree = tempfile.mkdtemp(prefix="hospitality-admin-order-modal-mut-")
    for rel in FILES:
        dst = os.path.join(tree, rel)
        os.makedirs(os.path.dirname(dst), exist_ok=True)
        shutil.copy(os.path.join(ROOT, rel), dst)
    return tree


def main():
    bad = 0
    tree = fresh_tree()
    rc, last, fails, groups = run(tree)
    print(f"BASELINE (current files, unmutated): rc={rc}  {last}")
    if rc != 0:
        print("  the baseline is not green, so no mutant result below means anything:")
        for l in fails[:8]:
            print("   ", l[:160])
        shutil.rmtree(tree)
        sys.exit(1)
    shutil.rmtree(tree)

    for mid, rel, what, old, new, want in MUTANTS:
        tree = fresh_tree()
        path = os.path.join(tree, rel)
        text = open(path, encoding="utf-8").read()
        if text.count(old) != 1:
            print(f"{mid}  REPLACEMENT DID NOT MATCH ONCE ({text.count(old)}x)  {what}")
            bad += 1
            shutil.rmtree(tree)
            continue
        open(path, "w", encoding="utf-8").write(text.replace(old, new))
        rc, last, fails, groups = run(tree)
        missed = set() if (want & groups) and "HANG" in want else want - groups
        verdict = "RED as expected" if rc != 0 and not missed else ("NOT RED" if rc == 0 else f"red, but NOT on {sorted(missed)}")
        if rc == 0 or missed:
            bad += 1
        print(f"{mid}  rc={rc}  {verdict}  red rows: {sorted(groups)}  — {what}")
        shutil.rmtree(tree)

    if "--no-base" not in sys.argv:
        tree = tempfile.mkdtemp(prefix="hospitality-admin-order-modal-base-")
        ok = True
        for rel in FILES:
            r = subprocess.run(["git", "-C", ROOT, "show", f"{BASE}:{rel}"], capture_output=True)
            if r.returncode != 0:
                print(f"BASE {BASE}: cannot read {rel}")
                ok = False
                break
            dst = os.path.join(tree, rel)
            os.makedirs(os.path.dirname(dst), exist_ok=True)
            open(dst, "wb").write(r.stdout)
        if ok:
            rc, last, fails, groups = run(tree)
            print(f"BASE {BASE}: rc={rc}  {last}")
            print(f"  FIX rows (red on base): {sorted(groups)}")
        shutil.rmtree(tree)

    print("ALL MUTANTS RED" if bad == 0 else f"{bad} PROBLEM(S)")
    sys.exit(1 if bad else 0)


if __name__ == "__main__":
    main()
