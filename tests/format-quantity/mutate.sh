#!/usr/bin/env bash
# Mutation driver for tests/format-quantity/run.mts (task 41367): each mutant carries ONE deliberate defect, in the
# helper or in a call site, and the harness must go red ON THE ROW THAT DEFECT BREAKS, not on a crash and not on
# every row. The unmutated tree must be green first, or the run proves nothing.
#
#   bash tests/format-quantity/mutate.sh
#
# A mutant counts as KILLED only when ALL hold:
#   - the harness exits 1 (0 = it stayed green; 2 = it crashed, and a crash kills nothing);
#   - every "must fail" line is in its output;
#   - no "must stay ok" line is (the row that is NOT broken stays green: the harness is not red everywhere).
# A mutant whose old text is not in the file exactly once is an error, never a pass.
#
# Exit code: 0 when the original passes and every mutant is killed on its own row, 1 otherwise.
set -u
export PYTHONDONTWRITEBYTECODE=1
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
src="$root/src/modules/bookingfrontend/client/src"
run="$root/tests/format-quantity/run.mts"
tmp="$(mktemp -d)"
bad=0

# The files the harness reads, mirrored per mutant so the real tree is never touched.
FILES="utils/article-pricing.ts utils/cost-utils.ts service/types/api/application.types.ts
components/user/application/application-price-lines.tsx components/user/application/catering-overview.tsx
components/checkout/hospitality/hospitality-order-modal.tsx components/checkout/articles/articles-section.tsx
components/article-table/article-table.tsx"

node "$run" >/dev/null 2>&1 || { echo "FAIL the unmutated tree is not green"; exit 1; }
echo "ok   original: green"

# mutate NAME FILE FROM TO "must fail A|must fail B" "must stay ok A|must stay ok B"
mutate() {
    local name="$1" file="$2" from="$3" to="$4" want_fail="$5" want_ok="$6"
    local mirror="$tmp/$name"
    local f
    for f in $FILES; do
        mkdir -p "$mirror/$(dirname "$f")"
        cp "$src/$f" "$mirror/$f"
    done
    FROM="$from" TO="$to" python3 -I -B - "$mirror/$file" <<'PY' || { echo "FAIL $name: the text to mutate is not in $file exactly once"; bad=1; return; }
import os, sys
path = sys.argv[1]
text = open(path, encoding='utf8').read()
frm, to = os.environ['FROM'], os.environ['TO']
if text.count(frm) != 1:
    sys.exit(1)
open(path, 'w', encoding='utf8').write(text.replace(frm, to))
PY
    local out rc
    out="$(CLIENT_SRC="$mirror" node "$run" 2>&1)"
    rc=$?
    if [ "$rc" -eq 0 ]; then echo "FAIL $name: the harness stayed GREEN on the broken code"; bad=1; return; fi
    if [ "$rc" -ne 1 ]; then echo "FAIL $name: the harness exited $rc (a crash kills nothing)"; bad=1; return; fi
    local line
    local IFS='|'
    for line in $want_fail; do
        [ -z "$line" ] && continue
        if ! grep -Fq -- "$line" <<<"$out"; then echo "FAIL $name: expected the red line «${line}» and it is not there"; bad=1; return; fi
    done
    for line in $want_ok; do
        [ -z "$line" ] && continue
        if grep -Fq -- "$line" <<<"$out"; then echo "FAIL $name: «${line}» is red too: the harness is not pinning the broken row"; bad=1; return; fi
    done
    echo "ok   $name: red on its own row ($(grep -c '^FAIL' <<<"$out") FAIL lines)"
}

DIGITS="{maximumFractionDigits: 2, useGrouping: false}"
RETURN_FINITE="return Number.isFinite(quantity) ? QUANTITY_FORMAT.format(quantity) : '';"
P=utils/article-pricing.ts

# ---- the helper: the declared mutant first (maxFractionDigits 0) -------------------------------------------
mutate fraction-digits-0 $P "$DIGITS" "{maximumFractionDigits: 0, useGrouping: false}" \
    'FAIL row "2.50" [no]|FAIL row 2.5 [no]|FAIL row "2.25" [no]|FAIL row 12345.5 [no]' \
    'FAIL row "3.00"|FAIL row 3 |FAIL row "1800.00"|FAIL row null'
mutate fraction-digits-1 $P "$DIGITS" "{maximumFractionDigits: 1, useGrouping: false}" \
    'FAIL row "2.25" [no]' \
    'FAIL row "2.50"|FAIL row "3.00"|FAIL row "1800.00"'
mutate grouping-on $P "$DIGITS" "{maximumFractionDigits: 2, useGrouping: true}" \
    'FAIL row "1800.00" [no]|FAIL row 12345.5 [no]' \
    'FAIL row "2.50"|FAIL row "3.00"|FAIL row null'
mutate locale-follows-the-ui "$P" "Intl.NumberFormat('nb-NO', $DIGITS" "Intl.NumberFormat(undefined, $DIGITS" \
    'FAIL row "2.50" [en]|FAIL separator [en]' \
    'FAIL row "2.50" [no]|FAIL row "2.50" [nn]|FAIL row "3.00"|FAIL separator [no]'
mutate locale-en-US-hard-wired "$P" "Intl.NumberFormat('nb-NO', $DIGITS" "Intl.NumberFormat('en-US', $DIGITS" \
    'FAIL row "2.50" [no]|FAIL row "2.50" [nn]|FAIL row "2.50" [en]|FAIL separator [no]' \
    'FAIL row "3.00"|FAIL row 3 |FAIL row null'
mutate null-is-zero $P "    if (value === null || value === undefined) return '';
" "" \
    'FAIL row null [no]' \
    'FAIL row undefined|FAIL row ""|FAIL row "3.00"'
mutate blank-string-is-zero $P "    if (typeof value === 'string' && value.trim() === '') return '';
" "" \
    'FAIL row "" [no]|FAIL row "   " [no]' \
    'FAIL row null|FAIL row undefined|FAIL row "abc"|FAIL row "3.00"'
mutate non-finite-shown $P "$RETURN_FINITE" "return QUANTITY_FORMAT.format(quantity);" \
    'FAIL row "abc" [no]|FAIL row NaN [no]|FAIL row Infinity [no]' \
    'FAIL row null|FAIL row ""|FAIL row "3.00"|FAIL row "2.50"'
mutate old-copy-string-number $P "$RETURN_FINITE" "return String(quantity);" \
    'FAIL row "2.50" [no]|FAIL row 2.5 [no]|FAIL row "2.25" [no]|FAIL separator [no]' \
    'FAIL row "3.00"|FAIL row 3 |FAIL row "1800.00"|FAIL row null'
mutate old-copy-math-round $P "$RETURN_FINITE" "return String(Math.round(quantity * 100) / 100);" \
    'FAIL row "2.50" [no]|FAIL row 2.5 [no]|FAIL separator [no]' \
    'FAIL row "3.00"|FAIL row 3 |FAIL row "1800.00"|FAIL row null'

# ---- the call sites: a copy that comes back, a render that goes back to the raw value, a narrowed type --------
PL=components/user/application/application-price-lines.tsx
CO=components/user/application/catering-overview.tsx
HM=components/checkout/hospitality/hospitality-order-modal.tsx
mutate price-lines-own-copy-again $PL "type Translate = (key: string, options?: any) => string;" \
    "type Translate = (key: string, options?: any) => string;
const formatQuantity = (quantity: number | string): string => String(Number(quantity));" \
    "FAIL site $PL: no local formatQuantity|FAIL exactly one definition" \
    "FAIL site $CO|FAIL site $HM|FAIL row"
mutate catering-overview-own-copy-again $CO "/** Application statuses in which catering may still be handled from Min side. */" \
    "const formatQuantity = (quantity: string): string => String(Number(quantity));
/** Application statuses in which catering may still be handled from Min side. */" \
    "FAIL site $CO: no local formatQuantity|FAIL exactly one definition" \
    "FAIL site $PL|FAIL site $HM|FAIL row"
mutate order-modal-own-copy-again $HM "const HospitalityOrderModal: FC<" \
    "function formatQuantity(quantity: number): string {
    return String(Math.round(quantity * 100) / 100);
}

const HospitalityOrderModal: FC<" \
    "FAIL site $HM: no local formatQuantity|FAIL exactly one definition" \
    "FAIL site $PL|FAIL site $CO|FAIL row"
mutate price-lines-import-dropped $PL "    formatQuantity,
" "" \
    "FAIL site $PL: imports the shared formatQuantity" \
    "FAIL site $PL: no local formatQuantity|FAIL site $CO|FAIL row"
mutate articles-section-prints-raw components/checkout/articles/articles-section.tsx \
    ": formatQuantity(line.quantity)}</Table.Cell>" ": line.quantity}</Table.Cell>" \
    "FAIL site articles-section: the line quantity prints through it" \
    "FAIL site articles-section: imports|FAIL site $PL|FAIL row"
mutate article-table-prints-raw components/article-table/article-table.tsx \
    "formatMinutes(quantity, t) : formatQuantity(quantity)) : 0}" "formatMinutes(quantity, t) : quantity) : 0}" \
    "FAIL site article-table: the selected quantity prints through it" \
    "FAIL site article-table: imports|FAIL site $PL|FAIL row"
mutate type-narrowed-to-number service/types/api/application.types.ts \
    "quantity: number | string;" "quantity: number;" \
    "FAIL type IOrderLine.quantity" \
    "FAIL site|FAIL row"

rm -rf "$tmp"
exit $bad
