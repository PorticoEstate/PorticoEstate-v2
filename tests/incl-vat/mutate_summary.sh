#!/usr/bin/env bash
# Mutation driver for tests/incl-vat/run_summary.mts (task 39791): each mutant of article-pricing.ts carries ONE
# deliberate defect in the price summary, and the harness must go red on it. The unmutated file must be green.
#
#   bash tests/incl-vat/mutate_summary.sh
#
# Exit code: 0 when the original passes and every mutant is caught, 1 otherwise.
set -u
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
src="$root/src/modules/bookingfrontend/client/src/utils/article-pricing.ts"
run="$root/tests/incl-vat/run_summary.mts"
tmp="$(mktemp -d)"
bad=0

node "$run" >/dev/null 2>&1 || { echo "FAIL the unmutated file is not green"; exit 1; }
echo "ok   original: green"

# name | exact text to replace | replacement
mutate() {
    local name="$1" from="$2" to="$3"
    FROM="$from" TO="$to" python3 - "$src" "$tmp/$name.ts" <<'PY' || { echo "FAIL $name: the text to mutate is not in the file"; bad=1; return; }
import os, sys
text = open(sys.argv[1], encoding='utf8').read()
frm, to = os.environ['FROM'], os.environ['TO']
if text.count(frm) != 1:
    sys.exit(1)
open(sys.argv[2], 'w', encoding='utf8').write(text.replace(frm, to))
PY
    if PRICING_TS="$tmp/$name.ts" node "$run" >/dev/null 2>&1; then
        echo "FAIL $name: the harness stayed GREEN on a broken summary"
        bad=1
    else
        echo "ok   $name: red"
    fi
}

mutate vat-floor-not-round \
    "vatOre: exOre === null || percent === null ? null : inclOre(exOre, percent) - exOre," \
    "vatOre: exOre === null || percent === null ? null : Math.floor(exOre * percent / 100),"
mutate unknown-percent-is-zero \
    "const percent = knownPercent(line.tax_percent);
    return {
        exOre,
        percent," \
    "const percent = knownPercent(line.tax_percent) ?? 0;
    return {
        exOre,
        percent,"
ORDER_LINE_BODY="const percent = knownPercent(line.tax_percent);
    return {exOre, vatOre, percent: percent !== null && inclOre(exOre, percent) - exOre === vatOre ? percent : null};"
mutate label-from-the-raw-ratio \
    "$ORDER_LINE_BODY" \
    "return {exOre, vatOre, percent: taxPercentOfOrderLine(line)};"
mutate null-percent-read-as-zero \
    "$ORDER_LINE_BODY" \
    "const percent = knownPercent(line.tax_percent) ?? 0;
    return {exOre, vatOre, percent: inclOre(exOre, percent) - exOre === vatOre ? percent : null};"
mutate field-ignored \
    "$ORDER_LINE_BODY" \
    "return {exOre, vatOre, percent: null};"
mutate agreement-check-dropped \
    "$ORDER_LINE_BODY" \
    "const percent = knownPercent(line.tax_percent);
    return {exOre, vatOre, percent};"
mutate agreement-check-always-true-for-zero-tax \
    "percent !== null && inclOre(exOre, percent) - exOre === vatOre ? percent : null};" \
    "percent !== null && (vatOre === 0 || inclOre(exOre, percent) - exOre === vatOre) ? percent : null};"
mutate zero-rows-kept \
    "    parts.filter(part => part.ore !== 0);" \
    "    parts.filter(() => true);"
mutate unknown-row-hidden \
    "    parts.filter(part => part.ore !== 0);" \
    "    parts.filter(part => part.ore !== 0 && part.ore !== null);"
mutate only-zero-rows-kept \
    "    parts.filter(part => part.ore !== 0);" \
    "    parts.filter(part => part.ore === 0);"
mutate rates-ascending \
    "a.percent === null ? 1 : b.percent === null ? -1 : b.percent - a.percent" \
    "a.percent === null ? 1 : b.percent === null ? -1 : a.percent - b.percent"
mutate free-line-makes-a-row \
    "if (line.exOre === 0 && line.vatOre === 0) continue;" \
    ""
mutate rows-listed-while-vat-unknown \
    "if (exOre !== null && vatOre !== null) {
        for (const line of lines) {" \
    "{
        for (const line of lines) {"
mutate rental-is-everything \
    "line.article_cat_id === ROOM_RENTAL_CATEGORY).map(pricedOrderLine)" \
    "line.article_cat_id !== 99).map(pricedOrderLine)"
mutate incl-drops-the-vat \
    "inclOre: exOre === null || vatOre === null ? null : exOre + vatOre, rates}" \
    "inclOre: exOre === null || vatOre === null ? null : exOre, rates}"

rm -rf "$tmp"
exit $bad
