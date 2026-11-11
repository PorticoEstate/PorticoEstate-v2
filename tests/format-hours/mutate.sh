#!/usr/bin/env bash
# Mutation driver for tests/format-hours/run.mts (task 42446): each mutant carries ONE deliberate defect, in the
# helper, in the language -> locale mapping, in a call site or in a .lang row, and the harness must go red ON THE
# ROW THAT DEFECT BREAKS, not on a crash and not on every row. The unmutated tree must be green first, or the run
# proves nothing.
#
#   bash tests/format-hours/mutate.sh
#
# A mutant counts as KILLED only when ALL hold:
#   - the harness exits 1 (0 = it stayed green; 2 = it crashed, and a crash kills nothing);
#   - every "must fail" line is in its output;
#   - no "must stay ok" line is (the rows that are NOT broken stay green: the harness is not red everywhere).
# A mutant whose old text is not in the file exactly once is an error, never a pass.
#
# ONE MUTANT IS KILLED BY A ROW AND NOT BY OUTPUT, ON PURPOSE: nn-sent-to-nn-NO (M2). Node has Nynorsk data, so
# 'nn' and 'nn-NO' print the same «2,5» as 'no' here and every output row stays green; Chrome 157 resolves both to
# en-US and prints «2.5» (ledger #42455). Only the locale-TAG row sees it, and its "must stay ok" list says so.
#
# LANG_DIR (optional) is the directory of phpgw_{no,nn,en}.lang the unmutated tree is read from.
#
# Exit code: 0 when the original passes and every mutant is killed on its own row, 1 otherwise.
set -u
export PYTHONDONTWRITEBYTECODE=1
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
src="$root/src/modules/bookingfrontend/client/src"
langs="${LANG_DIR:-$root/src/modules/bookingfrontend/setup}"
run="$root/tests/format-hours/run.mts"
tmp="$(mktemp -d)"
bad=0

# The files the harness reads, mirrored per mutant so the real tree is never touched.
FILES="utils/format-hours.ts utils/hospitality-deadline.ts components/user/application/application-details.tsx"
LANG_FILES="phpgw_no.lang phpgw_nn.lang phpgw_en.lang"

env -u CLIENT_SRC -u LANG_DIR LANG_DIR="$langs" node "$run" >/dev/null 2>&1 || { echo "FAIL the unmutated tree is not green"; exit 1; }
echo "ok   original: green"

# mutate NAME FILE FROM TO "must fail A|must fail B" "must stay ok A|must stay ok B"
# FILE is relative to the client src/ tree, or lang/<file> for a .lang row.
mutate() {
    local name="$1" file="$2" from="$3" to="$4" want_fail="$5" want_ok="$6"
    local mirror="$tmp/$name"
    local f
    for f in $FILES; do
        mkdir -p "$mirror/src/$(dirname "$f")"
        cp "$src/$f" "$mirror/src/$f"
    done
    mkdir -p "$mirror/lang"
    for f in $LANG_FILES; do cp "$langs/$f" "$mirror/lang/$f"; done
    local target="$mirror/src/$file"
    case "$file" in lang/*) target="$mirror/$file" ;; esac
    FROM="$from" TO="$to" python3 -I -B - "$target" <<'PY' || { echo "FAIL $name: the text to mutate is not in $file exactly once"; bad=1; return; }
import os, sys
path = sys.argv[1]
text = open(path, encoding='utf8').read()
frm, to = os.environ['FROM'], os.environ['TO']
if text.count(frm) != 1:
    sys.exit(1)
open(path, 'w', encoding='utf8').write(text.replace(frm, to))
PY
    local out rc
    out="$(CLIENT_SRC="$mirror/src" LANG_DIR="$mirror/lang" node "$run" 2>&1)"
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

FH=utils/format-hours.ts
HD=utils/hospitality-deadline.ts
AD=components/user/application/application-details.tsx
CALL='{formatHours(hours, i18n.language, t)}'
RAW='{hours} t'
NL=$'\n'
TAB=$'\t'

# ---- the declared mutants ---------------------------------------------------------------------------------
# M1: the locale hard-wired to nb-NO, the way formatQuantity and formatCurrency are: right for no, wrong for en
mutate M1-locale-hard-wired-nb-NO $FH "numberFormatFor(localeFor(lang))" "numberFormatFor('nb-NO')" \
    'FAIL row 2.5 [en] (default nb)|FAIL row 2.5 [en] (default en)|FAIL row 1.25 [en] (default nb)|FAIL locale [en] (default nb)' \
    'FAIL row 2.5 [no]|FAIL row 2.5 [nn]|FAIL locale [no]|FAIL lang key|FAIL site'
# M2: nn sent to 'nn-NO', the brief's own suggestion. Killed by the locale-TAG row ONLY: every output row stays green in Node.
mutate M2-nn-sent-to-nn-NO $HD "if (lang === 'nn') return 'no';" "if (lang === 'nn') return 'nn-NO';" \
    'FAIL locale [nn] (default nb)|FAIL locale [nn] (default en)' \
    'FAIL row 2.5 [nn]|FAIL row 2.5 [no]|FAIL row 2.5 [en]|FAIL locale [no]|FAIL locale [en]|FAIL lang key|FAIL site'
# M3: the unit hard-coded «t»: right for no and nn, wrong for en
mutate M3-unit-hard-coded-t $FH "t('bookingfrontend.hours_short')" "'t'" \
    'FAIL row 2.5 [en] (default nb)|FAIL row 3 [en] (default nb)' \
    'FAIL row 2.5 [no]|FAIL row 2.5 [nn]|FAIL locale|FAIL lang key|FAIL site'
# M4: a call site goes back to the raw value, one mutant per list, each red on its OWN list
mutate M4a-DatesList-raw-again $AD \
    'kl. ${to.time}`}'"$NL"'                            </div>'"$NL"'                        </div>'"$NL"'                        <div className={styles.dateDuration}>'"$CALL"'</div>' \
    'kl. ${to.time}`}'"$NL"'                            </div>'"$NL"'                        </div>'"$NL"'                        <div className={styles.dateDuration}>'"$RAW"'</div>' \
    'FAIL no raw|FAIL site components/user/application/application-details.tsx: exactly three|FAIL site DatesList' \
    'FAIL site RecurringSeriesList|FAIL site ReservedTimesList|FAIL row|FAIL locale|FAIL lang key'
mutate M4b-RecurringSeriesList-raw-again $AD \
    '{t(status.langKey)}</Tag>'"$NL"'                        <div className={styles.dateDuration}>'"$CALL"'</div>' \
    '{t(status.langKey)}</Tag>'"$NL"'                        <div className={styles.dateDuration}>'"$RAW"'</div>' \
    'FAIL no raw|FAIL site components/user/application/application-details.tsx: exactly three|FAIL site RecurringSeriesList' \
    'FAIL site DatesList|FAIL site ReservedTimesList|FAIL row|FAIL locale|FAIL lang key'
mutate M4c-ReservedTimesList-raw-again $AD \
    "$NL"'                            <div className={styles.dateDuration}>'"$CALL"'</div>' \
    "$NL"'                            <div className={styles.dateDuration}>'"$RAW"'</div>' \
    'FAIL no raw|FAIL site components/user/application/application-details.tsx: exactly three|FAIL site ReservedTimesList' \
    'FAIL site DatesList|FAIL site RecurringSeriesList|FAIL row|FAIL locale|FAIL lang key'
# M5: the language is not passed: the helper falls back to nb-NO and the English page prints «2,5 h»
mutate M5-language-not-passed $AD \
    "$NL"'                            <div className={styles.dateDuration}>'"$CALL"'</div>' \
    "$NL"'                            <div className={styles.dateDuration}>{formatHours(hours, undefined as any, t)}</div>' \
    'FAIL site components/user/application/application-details.tsx: exactly three|FAIL site ReservedTimesList' \
    'FAIL site DatesList|FAIL site RecurringSeriesList|FAIL no raw|FAIL row|FAIL locale|FAIL lang key'

# ---- beyond the declared five ------------------------------------------------------------------------------
# M6: the English unit row of the .lang file left at «t»
mutate M6-en-unit-in-lang-file-is-t lang/phpgw_en.lang "hours_short${TAB}bookingfrontend${TAB}en${TAB}h" "hours_short${TAB}bookingfrontend${TAB}en${TAB}t" \
    'FAIL lang key [en]|FAIL row 2.5 [en] (default nb)' \
    'FAIL lang key [no]|FAIL lang key [nn]|FAIL row 2.5 [no]|FAIL row 2.5 [nn]|FAIL locale|FAIL site'
# M7: the language hook removed from the first list (tsc would say so too; the harness says it on its own row)
mutate M7-DatesList-hook-removed $AD \
    'const t = useTrans();'"$NL"'    const {i18n} = useClientTranslation();'"$NL"'    const sorted = useMemo(' \
    'const t = useTrans();'"$NL"'    const sorted = useMemo(' \
    'FAIL site DatesList' \
    'FAIL site RecurringSeriesList|FAIL site ReservedTimesList|FAIL no raw|FAIL row|FAIL locale|FAIL lang key'
# M8: no locale at all: the browser/process default decides. Green by luck under one default, red under the other.
mutate M8-locale-omitted $FH "numberFormatFor(localeFor(lang))" "numberFormatFor(undefined as any)" \
    'FAIL row 2.5 [no] (default en)|FAIL row 2.5 [en] (default nb)|FAIL locale [en] (default nb)' \
    'FAIL row 2.5 [no] (default nb)|FAIL row 2.5 [nn] (default nb)|FAIL row 2.5 [en] (default en)|FAIL lang key|FAIL site'
# M9: grouping on («1 234,5» with a no-break space)
mutate M9-grouping-on $FH "useGrouping: false" "useGrouping: true" \
    'FAIL row 1234.5 [no] (default nb)|FAIL row 1234.5 [en] (default nb)' \
    'FAIL row 2.5 [|FAIL row 3 [|FAIL row 1 [|FAIL locale|FAIL site'
# M10: no decimals at all («2,5 t» becomes «3 t»)
mutate M10-fraction-digits-0 $FH "maximumFractionDigits: 2" "maximumFractionDigits: 0" \
    'FAIL row 2.5 [no] (default nb)|FAIL row 1.25 [en] (default nb)' \
    'FAIL row 1 [|FAIL row 3 [|FAIL locale|FAIL lang key|FAIL site'
# M11: a plain space between the number and the unit instead of the no-break space
mutate M11-plain-space $FH '${NBSP}${t(' ' ${t(' \
    'FAIL row 2.5 [no] (default nb)|FAIL row 3 [en] (default nb)' \
    'FAIL locale|FAIL lang key|FAIL site'
# M12: a second formatHours defined in the component file
mutate M12-own-copy-again $AD 'const DatesList: FC<{ dates: DateRowItem[] }> = ({dates}) => {' \
    'const formatHours = (h: number): string => String(h);'"$NL"'const DatesList: FC<{ dates: DateRowItem[] }> = ({dates}) => {' \
    'FAIL exactly one definition of formatHours' \
    'FAIL row|FAIL locale|FAIL lang key|FAIL no raw'

rm -rf "$tmp"
exit $bad
