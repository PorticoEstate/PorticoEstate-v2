#!/usr/bin/env bash
# Mutant for run_delivered_order_lines.sh's static arm (GH #1423, task 40289): the SELECT without am.article_cat_id must
# turn it red. The unmutated source must be green on the static arm first (the live arm tests what runs, not the source).
#
#   bash tests/application-view/mutate_delivered_order_lines.sh
set -u
here="$(cd "$(dirname "$0")" && pwd)"
repo="$(cd "$here/../.." && pwd)"
src="${SERVICE_TS:-$repo/src/WebSocket/node/src/modules/application/delivered-application.service.ts}"
tmp="$(mktemp -d)"
python3 -I -B - "$src" "$tmp/mutant.ts" <<'PY'
import sys
s = open(sys.argv[1], encoding='utf-8').read()
old = "am.unit, am.article_cat_id, ev.percent_ AS tax_percent"
assert s.count(old) == 1, s.count(old)
open(sys.argv[2], 'w', encoding='utf-8').write(s.replace(old, "am.unit, ev.percent_ AS tax_percent"))
PY
[ $? -eq 0 ] || { echo "mutation not applied"; exit 2; }
if SERVICE_TS="$src" bash "$here/run_delivered_order_lines.sh" 2>&1 | grep -E "^FAIL static" ; then echo "baseline static arm RED: fix first"; exit 2; fi
if SERVICE_TS="$tmp/mutant.ts" bash "$here/run_delivered_order_lines.sh" 2>&1 | grep -E "^FAIL static: fetchOrders does not select"; then echo "MUTANT drop-column: killed"; exit 0; fi
echo "MUTANT drop-column: SURVIVED"; exit 1
