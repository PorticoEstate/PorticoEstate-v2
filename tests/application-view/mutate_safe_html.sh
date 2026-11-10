#!/usr/bin/env bash
# Mutation driver for run_safe_html.mts (GH #1423, task 40289): each mutant (mutate_safe_html.py) weakens
# utils/safe-html.ts in one way and the harness MUST turn red on it (exit 1). The unmutated source must be green
# first, or the run proves nothing. A mutant whose old text is not in the source is an error, not a pass.
#
#   bash tests/application-view/mutate_safe_html.sh
set -u
export PYTHONDONTWRITEBYTECODE=1
here="$(cd "$(dirname "$0")" && pwd)"
repo="$(cd "$here/../.." && pwd)"
src="$repo/src/modules/bookingfrontend/client/src/utils/safe-html.ts"
tmp="$(mktemp -d)"

if ! node "$here/run_safe_html.mts" >/dev/null; then echo "BASELINE RED: the unmutated source must pass first"; exit 2; fi
echo "baseline: green"

fail=0
for name in $(python3 -I -B -c "import sys; sys.path.insert(0, '$here'); import mutate_safe_html as m; print(' '.join(m.MUTANTS))"); do
  out="$tmp/$name.ts"
  if ! python3 -I -B - "$here" "$src" "$out" "$name" <<'PY'
import sys
here, src, out, name = sys.argv[1:5]
sys.path.insert(0, here)
import mutate_safe_html as m
old, new = m.MUTANTS[name]
s = open(src, encoding='utf-8').read()
if s.count(old) != 1:
    print(f"old text occurs {s.count(old)} times"); sys.exit(3)
open(out, 'w', encoding='utf-8').write(s.replace(old, new))
PY
  then echo "MUTANT $name: not applied - fix the mutation"; fail=1; continue; fi
  if SAFE_HTML_TS="$out" node "$here/run_safe_html.mts" >/dev/null 2>&1; then
    echo "MUTANT $name: SURVIVED (harness stayed green)"; fail=1
  else
    echo "MUTANT $name: killed"
  fi
done
exit $fail
