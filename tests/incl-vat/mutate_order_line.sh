#!/usr/bin/env bash
# Mutation driver for tests/incl-vat/run_order_line.php (task 39791): each mutant carries ONE deliberate defect in a
# TEMP COPY of the files the harness reads (never the tree), and the harness must go red. The unmutated copy must be
# green first, and every replacement must match exactly once.
#
#   VENDOR_AUTOLOAD=/path/to/vendor/autoload.php bash tests/incl-vat/mutate_order_line.sh
#
# Exit code: 0 when the original passes and every mutant is caught, 1 otherwise.
set -u
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
run="$root/tests/incl-vat/run_order_line.php"
tmp="$(mktemp -d)"
bad=0

FILES=(
    "src/traits/SerializableTrait.php"
    "src/modules/booking/models/OrderLine.php"
    "src/modules/booking/models/Order.php"
    "src/modules/bookingfrontend/repositories/ApplicationRepository.php"
    "src/modules/bookingfrontend/repositories/ArticleRepository.php"
    "src/WebSocket/node/src/modules/application/dto/order-line.dto.ts"
    "src/WebSocket/node/src/modules/application/application.service.ts"
    "src/WebSocket/node/src/modules/application/delivered-application.service.ts"
)
fresh_tree() {
    rm -rf "$tmp/tree"
    for f in "${FILES[@]}"; do
        mkdir -p "$tmp/tree/$(dirname "$f")"
        cp "$root/$f" "$tmp/tree/$f"
    done
}

fresh_tree
HD_ROOT="$tmp/tree" php "$run" >/dev/null 2>&1 || { echo "FAIL the unmutated copy is not green"; rm -rf "$tmp"; exit 1; }
echo "ok   original: green"

# name | file | exact text | replacement
mutate() {
    local name="$1" file="$2" from="$3" to="$4"
    fresh_tree
    FROM="$from" TO="$to" python3 - "$tmp/tree/$file" <<'PY' || { echo "FAIL $name: the text to mutate is not in $file exactly once"; bad=1; return; }
import os, sys
path = sys.argv[1]
text = open(path, encoding='utf8').read()
frm, to = os.environ['FROM'], os.environ['TO']
if text.count(frm) != 1:
    sys.exit(1)
open(path, 'w', encoding='utf8').write(text.replace(frm, to))
PY
    if HD_ROOT="$tmp/tree" php "$run" >/dev/null 2>&1; then
        echo "FAIL $name: the harness stayed GREEN on a broken tree"
        bad=1
    else
        echo "ok   $name: red"
    fi
}

M=src/modules/booking/models/OrderLine.php
A=src/modules/bookingfrontend/repositories/ApplicationRepository.php
R=src/modules/bookingfrontend/repositories/ArticleRepository.php
N=src/WebSocket/node/src/modules/application

mutate model-loses-the-property "$M" "    public \$tax_percent;" ""
mutate model-loses-expose "$M" "     * @OA\\Property(type=\"integer\", nullable=true)
     * @Expose
     */
    public \$tax_percent;" "     * @OA\\Property(type=\"integer\", nullable=true)
     */
    public \$tax_percent;"
mutate query-loses-the-join "$A" "                LEFT JOIN fm_ecomva ev ON ev.id = pol.tax_code
" ""
mutate query-joins-on-the-wrong-key "$A" "LEFT JOIN fm_ecomva ev ON ev.id = pol.tax_code" "LEFT JOIN fm_ecomva ev ON ev.id = pol.id"
mutate query-becomes-an-inner-join "$A" "LEFT JOIN fm_ecomva ev ON ev.id = pol.tax_code" "JOIN fm_ecomva ev ON ev.id = pol.tax_code"
mutate query-changes-the-where "$A" "WHERE po.cancelled IS NULL AND po.application_id = :application_id
                ORDER BY pol.id\";" "WHERE po.application_id = :application_id
                ORDER BY pol.id\";"
mutate query-gains-another-join "$A" "                LEFT JOIN fm_ecomva ev ON ev.id = pol.tax_code
" "                LEFT JOIN fm_ecomva ev ON ev.id = pol.tax_code
                LEFT JOIN bb_building bx ON bx.id = po.id
"
mutate query-loses-the-column "$A" "ev.percent_ AS tax_percent," "ev.percent_ AS pct,"
mutate writer-stores-the-percent "$R" "tax_code, unit_price, parent_mapping_id, amount, tax, currency,
            article_price_id, price_label{\$categoryColumn}" "tax_code, tax_percent, unit_price, parent_mapping_id, amount, tax, currency,
            article_price_id, price_label{\$categoryColumn}"

mutate node-dto-loses-expose "$N/dto/order-line.dto.ts" "  @Expose()
  tax_percent: number | null;" "  tax_percent: number | null;"
mutate node-application-loses-the-join "$N/application.service.ts" "       LEFT JOIN fm_ecomva ev ON ev.id = pol.tax_code
" ""
mutate node-application-joins-on-the-wrong-key "$N/application.service.ts" "LEFT JOIN fm_ecomva ev ON ev.id = pol.tax_code" "LEFT JOIN fm_ecomva ev ON ev.id = pol.id"
mutate node-application-changes-the-where "$N/application.service.ts" "LEFT JOIN fm_ecomva ev ON ev.id = pol.tax_code
       WHERE po.cancelled IS NULL" "LEFT JOIN fm_ecomva ev ON ev.id = pol.tax_code
       WHERE 1 = 1"
mutate node-delivered-loses-the-join "$N/delivered-application.service.ts" "       LEFT JOIN fm_ecomva ev ON ev.id = pol.tax_code
" ""
mutate node-delivered-loses-the-column "$N/delivered-application.service.ts" "ev.percent_ AS tax_percent," "ev.percent_ AS pct,"
mutate node-delivered-gains-another-join "$N/delivered-application.service.ts" "       LEFT JOIN fm_ecomva ev ON ev.id = pol.tax_code
" "       LEFT JOIN fm_ecomva ev ON ev.id = pol.tax_code
       LEFT JOIN bb_building bx ON bx.id = po.id
"

rm -rf "$tmp"
exit $bad
