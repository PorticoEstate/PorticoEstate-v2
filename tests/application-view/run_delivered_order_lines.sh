#!/usr/bin/env bash
# The lines of a DELIVERED application (the lane Min side reads over the websocket) carry article_cat_id: 1 for the
# room, 2 for an add-on (GH #1423, task 40289). Min side splits «Romleie» from «Utstyr og tjenester» on it; without it
# every line was an add-on and the room price sat under «Utstyr og tjenester».
#
#   bash tests/application-view/run_delivered_order_lines.sh [application ids...]     (default 84024 84219)
#
# STATIC arm (source, hermetic): the SELECT of fetchOrders in delivered-application.service.ts names am.article_cat_id,
#   and that file holds no INSERT/UPDATE/DELETE (the change is a read). SERVICE_TS points at an alternative copy of the
#   source; mutate_delivered_order_lines.sh uses it to prove that dropping the column turns this arm red.
# LIVE arm (needs the pe-ws container): runs the COMPILED service in /app/dist against the real database, for each id,
#   and checks that every line has an article_cat_id of 1 or 2 and that it is the one the database holds for the line's
#   article mapping (the database is asked independently, not the service).
#   It tests what is RUNNING: after a source change it is green only once the websocket server has been rebuilt.
# Exit code: 0 all pass, 1 any failure.
set -u
here="$(cd "$(dirname "$0")" && pwd)"
repo="$(cd "$here/../.." && pwd)"
src="${SERVICE_TS:-$repo/src/WebSocket/node/src/modules/application/delivered-application.service.ts}"
ids=("$@"); [ ${#ids[@]} -eq 0 ] && ids=(84024 84219)
fail=0

# --- static arm
select_block="$(python3 -I -B - "$src" <<'PY'
import re, sys
s = open(sys.argv[1], encoding='utf-8').read()
m = re.search(r"private async fetchOrders\(.*?\n  }\n", s, re.S)
print(m.group(0) if m else '')
PY
)"
if [ -z "$select_block" ]; then echo "FAIL static: fetchOrders not found in $src"; exit 1; fi
if grep -q "am\.article_cat_id," <<<"$select_block"; then echo "ok   static: fetchOrders selects am.article_cat_id"; else echo "FAIL static: fetchOrders does not select am.article_cat_id"; fail=1; fi
if grep -Eq "(INSERT +INTO|DELETE +FROM|UPDATE +[a-z_]+ +SET)" "$src"; then echo "FAIL static: the service holds a write statement"; fail=1; else echo "ok   static: no INSERT INTO / DELETE FROM / UPDATE .. SET in the service"; fi

# --- live arm
for id in "${ids[@]}"; do
  out="$(docker exec -i -w /app pe-ws node - "$id" <<'JS' 2>&1
const {Pool} = require('pg');
const {PhpConfigService} = require('/app/dist/config/php-config.service');
const {DeliveredApplicationService} = require('/app/dist/modules/application/delivered-application.service');
(async () => {
  const cfg = new PhpConfigService(); cfg.onModuleInit();
  const c = cfg.getDbConfig();
  const pool = new Pool({host: c.db_host, port: parseInt(c.db_port, 10), database: c.db_name, user: c.db_user, password: c.db_pass, max: 2});
  const id = parseInt(process.argv[2], 10);
  const svc = new DeliveredApplicationService({query: (sql, params) => pool.query(sql, params)});
  const orders = await svc.fetchOrders(id);
  const lines = orders.flatMap(o => o.lines);
  const truth = new Map((await pool.query(
    `SELECT pol.article_mapping_id AS m, am.article_cat_id AS cat FROM bb_purchase_order po JOIN bb_purchase_order_line pol ON po.id = pol.order_id
     JOIN bb_article_mapping am ON am.id = pol.article_mapping_id WHERE po.cancelled IS NULL AND po.application_id = $1`, [id])).rows.map(r => [r.m, r.cat]));
  const missing = lines.filter(l => !(l.article_cat_id === 1 || l.article_cat_id === 2));
  const wrong = lines.filter(l => l.article_cat_id !== truth.get(l.article_mapping_id));
  const cats = [...new Set(lines.map(l => l.article_cat_id))].sort();
  console.log(JSON.stringify({lines: lines.length, cats, missing: missing.length, wrong: wrong.length}));
  await pool.end();
})().catch(e => { console.log('ERROR ' + e.message); process.exit(2); });
JS
)"
  if grep -q '^{' <<<"$out" && node -e 'const r=JSON.parse(process.argv[1]); process.exit(r.lines>0 && r.missing===0 && r.wrong===0 ? 0 : 1)' "$(grep '^{' <<<"$out")"; then
    echo "ok   live $id: $(grep '^{' <<<"$out")"
  else
    echo "FAIL live $id: $out"; fail=1
  fi
done
exit $fail
