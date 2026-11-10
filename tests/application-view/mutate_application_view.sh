#!/usr/bin/env bash
# Mutation driver for run_application_view.mts (GH #1423, tasks 40289 and 40439): each mutant (mutate_application_view.py) weakens
# one of the files under test in a COPY of utils/ (and of the three application components) and the harness MUST turn red on it. The unmutated tree must be green
# first, or the run proves nothing. A mutant whose old text is not found exactly once is an error, not a pass.
#
#   bash tests/application-view/mutate_application_view.sh
set -u
export PYTHONDONTWRITEBYTECODE=1
here="$(cd "$(dirname "$0")" && pwd)"
repo="$(cd "$here/../.." && pwd)"
rel="src/modules/bookingfrontend/client/src/utils"
crel="src/modules/bookingfrontend/client/src/components/user/application"

if ! node "$here/run_application_view.mts" >/dev/null; then echo "BASELINE RED: the unmutated tree must pass first"; exit 2; fi
echo "baseline: green"

fail=0
for name in $(python3 -I -B -c "import sys; sys.path.insert(0, '$here'); import mutate_application_view as m; print(' '.join(m.MUTANTS))"); do
  tree="$(mktemp -d)"
  mkdir -p "$tree/$rel"
  cp "$repo/$rel/"*.ts "$tree/$rel/"
  mkdir -p "$tree/$crel"
  cp "$repo/$crel/catering-overview.tsx" "$repo/$crel/application-price-lines.tsx" "$repo/$crel/application-header.tsx" "$tree/$crel/"
  if ! python3 -I -B - "$here" "$tree/$rel" "$name" <<'PY'
import sys
here, utils, name = sys.argv[1:4]
sys.path.insert(0, here)
import mutate_application_view as m
file, old, new = m.MUTANTS[name]
path = f"{utils}/{file}"
s = open(path, encoding='utf-8').read()
if s.count(old) != 1:
    print(f"old text occurs {s.count(old)} times in {file}"); sys.exit(3)
open(path, 'w', encoding='utf-8').write(s.replace(old, new))
PY
  then echo "MUTANT $name: not applied - fix the mutation"; fail=1; continue; fi
  if AV_ROOT="$tree" node "$here/run_application_view.mts" >/dev/null 2>&1; then
    echo "MUTANT $name: SURVIVED (harness stayed green)"; fail=1
  else
    echo "MUTANT $name: killed"
  fi
done
exit $fail
