#!/usr/bin/env bash
#
# pe13650-build-recurring-allocation.sh
#
# NON-UI fixture builder: creates an *admin-side recurring (repeating) allocation*
# on the local pe-api stack, end to end, with no browser.
#
# This is the ADMIN counterpart to the user-side builder from task #13638
# (tests/e2e/pe13638-build-recurring-application.sh). The rows it produces have
# bb_allocation.application_id IS NULL -- the caseworker-created population that
# has no application level above it.
#
# Built from a recorded browser run of the operator's steps (task #13650).
# The recording it replays lives beside this file:
#   tests/e2e/pe13650-network-recording.md
#
# THE FLOW IS A THREE-STEP WIZARD, NOT ONE POST. booking.uiallocation.add reads
# `step` from the request and increments it on successful validation:
#   POST (no step)  -> step becomes 2 -> renders the PREVIEW (valid/invalid dates). Saves NOTHING.
#   POST step=2     -> step becomes 3 -> saves one bb_allocation row PER occurrence, then 302s.
# A builder that posts once and stops has created nothing and gets a 200 for it.
#
# Everything is plain application/x-www-form-urlencoded over HTTPS. The admin UI
# opens no websocket at all, so no socket handling is needed.
#
# Usage:
#   bash tests/e2e/pe13650-build-recurring-allocation.sh
#   RESOURCE_IDS="123 93" REPEAT_WEEKS=6 bash tests/e2e/pe13650-build-recurring-allocation.sh
#   DRY_RUN=1 ...     # stop after the preview; creates nothing
#
# Env (all optional):
#   BUILDING_NAME  default "Fana kulturhus"   EXACT match; "Fana kulturhus" alone is ambiguous
#                                             (also matches "Fana kulturhus LINKEN")
#   ORG_NAME       default "Bolleball forening" -- matched case-insensitively, see ORG_QUERY
#   ORG_QUERY      default "lleball"          substring sent to the org autocomplete
#   SEASON_ID      default: the season covering START date for this building
#   RESOURCE_IDS   default "123 93"           space separated; must belong to the building
#   START_DAYS     default: DERIVED PER RUN, 371..670 days from today -- see the
#                                             derivation below. The floor stays >= 365 so the
#                                             fixture keeps clear of the user-facing tests,
#                                             per the brief. An explicit START_DAYS still wins.
#   START_HOUR     default 17                 LOCAL wall-clock hour
#   DURATION_MIN   default 60
#   REPEAT_WEEKS   default 5                  weeks until repeat_until
#   INTERVAL       default 1                  repeat every N weeks; the UI only offers 1..4
#   COST           default 750
#   MAX_SHIFT      default 14                 how many one-day shifts to try when step 1
#                                             refuses the slot as already occupied. Only
#                                             applies to the DERIVED offset; if you set
#                                             START_DAYS yourself the run fails instead.
#   TAG            default "PE13650 <timestamp>"  written to additional_invoice_information,
#                                             which PERSISTS -- this is how you find the rows again
#   BASE           default https://pe-api.test
#   LOGIN/PASSWD   default henning / from AUTH-DETAILS.md
#
# Prints, on the last two lines:
#   ALLOCATION_TAG=<tag>
#   LAST_ALLOCATION_ID=<n>
#
set -euo pipefail

BASE="${BASE:-https://pe-api.test}"
LOGIN="${LOGIN:-henning}"
PASSWD="${PASSWD:-He569348}"
BUILDING_NAME="${BUILDING_NAME:-Fana kulturhus}"
ORG_QUERY="${ORG_QUERY:-lleball}"
RESOURCE_IDS="${RESOURCE_IDS:-123 93}"

# ONE timestamp feeds BOTH the tag and the day offset, so a second run in the
# same session moves its SLOT as well as its TAG. Before this, only the tag
# moved: two runs on the same day requested the same slot, and because the admin
# path conflict-checks, the second one FAILED at step 1 with
# "Overlapper med eksisterende tildeling" and saved nothing.
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"

# Day offset, derived from the seconds-of-day inside $STAMP. Two runs started D
# seconds apart get day offsets (D mod 300) apart, so back-to-back runs land on
# a different day AND a different weekday -- the weekday is what matters, because
# a recurring series occupies the same weekday for REPEAT_WEEKS weeks.
# The 371 floor is kept: >= 365 is the namespacing intent, and the variation sits
# INSIDE that, 371..670. Worst case the series ends at 670 + 5*7 = 705 days out,
# which is still inside the building season (measured: season 1024 for
# Fana kulturhus runs 2026-01-01..2029-09-26).
# RESIDUAL, documented rather than fixed: two runs separated by exactly 0, 7, 14,
# 21, 28 or 35 seconds modulo 300 reuse a weekday inside the repeat span and can
# share an occurrence date. A stateless timestamp cannot rule that out.
_HMS="${STAMP#*T}"; _HMS="${_HMS%Z}"
# Remember whether the caller pinned the offset BEFORE we default it: an explicit
# START_DAYS must win outright, which means it must also disable the shift below.
if [ -n "${START_DAYS:-}" ]; then START_DAYS_EXPLICIT=1; else START_DAYS_EXPLICIT=0; fi
START_DAYS="${START_DAYS:-$(( 371 + (10#${_HMS:0:2} * 3600 + 10#${_HMS:2:2} * 60 + 10#${_HMS:4:2}) % 300 ))}"

# Varying the offset is necessary but NOT sufficient, measured: this window
# already holds fixtures from earlier runs, and step 1 refuses to overlap ANY of
# them, not just this session's. A derived offset that happens to land on one
# fails exactly like the bug this replaces. So when we own the offset we shift it
# forward a day at a time until step 1 accepts. One day is the right step: a
# recurring series occupies one weekday, so +1 leaves the whole occupied lattice.
MAX_SHIFT="${MAX_SHIFT:-14}"

# 17, not 15: the UI run recorded in pe13650-network-recording.md already holds
# 15:00-16:00 on this resource pair, and step 1 correctly refuses to overlap it.
START_HOUR="${START_HOUR:-17}"
DURATION_MIN="${DURATION_MIN:-60}"
REPEAT_WEEKS="${REPEAT_WEEKS:-5}"
INTERVAL="${INTERVAL:-1}"
COST="${COST:-750}"
TAG="${TAG:-PE13650 $STAMP}"

JAR="$(mktemp -t pe13650cookies.XXXXXX)"
trap 'rm -f "$JAR"' EXIT

# -k: the dev cert is self-signed.
C=(curl -sk --cookie-jar "$JAR" --cookie "$JAR")

die() { echo "FATAL: $*" >&2; exit 1; }
need() { command -v "$1" >/dev/null || die "missing dependency: $1"; }
need curl; need jq; need python3

# ---------------------------------------------------------------- 1. login ---
# The admin login is NOT the citizen login and does NOT need a priming GET.
# A cold POST /login with logindomain=default establishes the session outright.
# (Contrast tests/e2e/pe13638-*.sh, where /bookingfrontend/login/ only BINDS a
# user onto a session that must already exist.)
# Negative arm, measured: a wrong password makes /login answer 404, and every
# later ?menuaction= call answers 302 with a zero-length body.
LOGIN_CODE="$("${C[@]}" -o /dev/null -w '%{http_code}' -X POST \
  -d 'logindomain=default' \
  --data-urlencode "login=$LOGIN" --data-urlencode "passwd=$PASSWD" \
  "$BASE/login")"
[ "$LOGIN_CODE" = "200" ] || die "admin login returned $LOGIN_CODE (a wrong password gives 404)"

# Prove the session actually carries admin rights, rather than trusting the 200.
ADD_PAGE="$("${C[@]}" "$BASE/?menuaction=booking.uiallocation.add")"
echo "$ADD_PAGE" | command grep -q 'name="building_name"' \
  || die "logged in but the allocation form did not render; session is not an admin session"
echo "logged in as $LOGIN; booking.uiallocation.add renders"

# --------------------------------------------------- 2. reference lookups ---
# These are the four endpoints the form's own JS calls (booking/js/base/allocation.js).
J() { "${C[@]}" "$1"; }

BUILDING_ID="$(J "$BASE/?menuaction=booking.uibuilding.index&phpgw_return_as=json&query=$(python3 -c 'import sys,urllib.parse;print(urllib.parse.quote(sys.argv[1]))' "$BUILDING_NAME")" \
  | jq -r --arg n "$BUILDING_NAME" '[.data[] | select(.name == $n)] | if length == 1 then .[0].id
        elif length == 0 then error("no building named \($n)")
        else error("building name \($n) is ambiguous") end')"
echo "building: $BUILDING_NAME = $BUILDING_ID"

ORG_JSON="$(J "$BASE/?menuaction=booking.uiorganization.index&filter_active=1&phpgw_return_as=json&query=$ORG_QUERY" \
  | jq -c '[.data[]] | if length == 1 then .[0] else error("org query matched \(length) rows, expected exactly 1") end')"
ORG_ID="$(echo "$ORG_JSON" | jq -r .id)"
ORG_NAME="$(echo "$ORG_JSON" | jq -r .name)"
echo "organization: $ORG_NAME = $ORG_ID"

# ------------------------------------------- 3./4. dates, season, preview ---
# These three are ONE unit and therefore one loop: the dates come from
# START_DAYS, the season covers the dates, and step 1 either accepts the slot or
# refuses it. When step 1 refuses AND we own the offset, we move START_DAYS on by
# a day and re-derive all three, rather than failing the run.
SEASON_ID_EXPLICIT="${SEASON_ID:+1}"
ATTEMPT=0

while : ; do
  # from_/to_ use the datetimepicker's format d/m-Y H:i; repeat_until uses d/m-Y.
  # Both are LOCAL wall-clock -- bb_allocation.from_/to_ are naive local timestamps.
  # NB: from_/to_ contain a space, so these are '|'-separated, not whitespace-separated.
  IFS='|' read -r FROM_S TO_S REPEAT_UNTIL_S SEASON_DATE <<EOF
$(python3 - "$START_DAYS" "$START_HOUR" "$DURATION_MIN" "$REPEAT_WEEKS" <<'PY'
import sys, datetime
days, hour, dur, weeks = (int(x) for x in sys.argv[1:5])
start = (datetime.datetime.now() + datetime.timedelta(days=days)).replace(
    hour=hour, minute=0, second=0, microsecond=0)
end = start + datetime.timedelta(minutes=dur)
f = lambda d: d.strftime('%d/%m-%Y %H:%M')
print('|'.join([f(start), f(end),
                (start + datetime.timedelta(weeks=weeks)).strftime('%d/%m-%Y'),
                start.strftime('%Y-%m-%d')]))
PY
)
EOF

  # Season: the one covering the start date for this building, which is what the
  # server itself re-derives per occurrence (class.uiallocation.inc.php:668-683).
  if [ -z "$SEASON_ID_EXPLICIT" ]; then
    # The endpoint returns from_/to_ as d/m-Y, so pick the covering season in python
    # rather than string-comparing them. "Denne interntildelingen ligger utenfor
    # angitt sesong" is what you get when this is wrong.
    # NB: the JSON goes in as argv, not on stdin -- a heredoc would replace stdin
    # and silently discard a pipe.
    SEASON_JSON="$(J "$BASE/?menuaction=booking.uiseason.index&sort=name&filter_building_id=$BUILDING_ID&filter_now=1&length=-1&phpgw_return_as=json")"
    SEASON_ID="$(python3 - "$SEASON_DATE" "$SEASON_JSON" <<'PY'
import sys, json, datetime
want = datetime.datetime.strptime(sys.argv[1], '%Y-%m-%d').date()
p = lambda s: datetime.datetime.strptime(s, '%d/%m-%Y').date()
rows = json.loads(sys.argv[2]).get('data', [])
hits = [r for r in rows if r.get('active') == 1 and p(r['from_']) <= want <= p(r['to_'])]
if not hits:
    sys.exit('no active season covers {} for this building; candidates: {}'.format(
        want, [(r['id'], r['name'], r['from_'], r['to_']) for r in rows]))
print(hits[0]['id'])
PY
    )" || die "season lookup failed"
  fi
  echo "season: $SEASON_ID"
  echo "START_DAYS=$START_DAYS (offset from today)"
  echo "slot $FROM_S -> $TO_S (local), every $INTERVAL week(s) until $REPEAT_UNTIL_S"

  # No `step` in the body: the server defaults it to 1 and increments to 2.
  # `repeat_until` being non-empty is what puts add() on the recurring branch --
  # there is no "recurring" checkbox on this form (unlike edit()).
  FORM=(--data-urlencode "building_id=$BUILDING_ID"
        --data-urlencode "building_name=$BUILDING_NAME"
        --data-urlencode "organization_id=$ORG_ID"
        --data-urlencode "organization_name=$ORG_NAME"
        --data-urlencode "from_=$FROM_S"
        --data-urlencode "to_=$TO_S"
        --data-urlencode "repeat_until=$REPEAT_UNTIL_S"
        --data-urlencode "field_interval=$INTERVAL"
        --data-urlencode "season_id=$SEASON_ID"
        --data-urlencode "cost=$COST"
        --data-urlencode "additional_invoice_information=$TAG"
        --data-urlencode "application_id="
        --data-urlencode "skip_bas=0"
        --data-urlencode "outseason=")
  for r in $(echo "$RESOURCE_IDS"); do FORM+=(--data-urlencode "resources[]=$r"); done

  PREVIEW="$("${C[@]}" -X POST "${FORM[@]}" "$BASE/?menuaction=booking.uiallocation.add")"

  if echo "$PREVIEW" | command grep -q 'name="step" value="2"'; then
    break
  fi

  ERRS="$(echo "$PREVIEW" | command grep -o 'class="error"[^<]*<[^>]*>[^<]*' | head -5 || true)"

  # An explicit START_DAYS is an instruction, not a suggestion: do not shift it,
  # fail instead, so the caller sees that the slot they asked for is unavailable.
  [ "$START_DAYS_EXPLICIT" = "0" ] \
    || die "step 1 refused the slot and START_DAYS was set explicitly, so it was NOT
shifted. The slot you asked for is unavailable, or the form is otherwise invalid.
$ERRS"

  [ "$ATTEMPT" -lt "$MAX_SHIFT" ] \
    || die "step 1 still refuses after $MAX_SHIFT one-day shifts from the derived offset.
That is not ordinary occupancy; season_id may not cover the dates, a resource may not
belong to the building, or from_/to_ are not in d/m-Y H:i.
$ERRS"

  ATTEMPT=$((ATTEMPT + 1))
  START_DAYS=$((START_DAYS + 1))
  echo "step 1 refused that slot; shifting to START_DAYS=$START_DAYS (shift $ATTEMPT/$MAX_SHIFT). Reason:"
  echo "$ERRS"
done

TEMP_ID="$(echo "$PREVIEW" | command grep -o 'name="temp_id" value="[^"]*"' | command sed 's/.*value="//;s/"//')"
WEEKDAY="$(echo "$PREVIEW"  | command grep -o 'name="weekday" value="[^"]*"'  | command sed 's/.*value="//;s/"//')"
N_VALID="$(echo   "$PREVIEW" | command grep -c 'tr class="table-success"' || true)"
N_INVALID="$(echo "$PREVIEW" | command grep -c 'tr class="table-warning"' || true)"
echo "preview: $N_VALID date(s) can be created, $N_INVALID in conflict (temp_id=$TEMP_ID)"

[ "$N_VALID" -gt 0 ] || die "preview reports 0 creatable dates; step 3 would create nothing"

if [ "${DRY_RUN:-0}" = "1" ]; then
  echo "DRY_RUN=1, stopping before the create. Nothing was written."
  exit 0
fi

# ------------------------------------------- 5. step 2 -> 3 : the real save ---
# This is the call that writes. One bb_allocation row per valid occurrence, each
# with application_id NULL. Answers 302 -> booking.uiallocation.show&id=<LAST id>.
CREATE=(--data-urlencode "building_id=$BUILDING_ID"
        --data-urlencode "building_name=$BUILDING_NAME"
        --data-urlencode "organization_id=$ORG_ID"
        --data-urlencode "organization_name=$ORG_NAME"
        --data-urlencode "from_=$FROM_S"
        --data-urlencode "to_=$TO_S"
        --data-urlencode "weekday=$WEEKDAY"
        --data-urlencode "repeat_until=$REPEAT_UNTIL_S"
        --data-urlencode "field_interval=$INTERVAL"
        --data-urlencode "season_id=$SEASON_ID"
        --data-urlencode "cost=$COST"
        --data-urlencode "additional_invoice_information=$TAG"
        --data-urlencode "application_id="
        --data-urlencode "skip_bas=0"
        --data-urlencode "outseason="
        --data-urlencode "temp_id=$TEMP_ID"
        --data-urlencode "step=2"
        --data-urlencode "create=Lagre")
for r in $(echo "$RESOURCE_IDS"); do CREATE+=(--data-urlencode "resources[]=$r"); done

LOCATION="$("${C[@]}" -o /dev/null -w '%{redirect_url}' -X POST "${CREATE[@]}" \
  "$BASE/?menuaction=booking.uiallocation.add")"

case "$LOCATION" in
  *uiallocation.show*id=*) : ;;
  *uiallocation.index*)    die "step 3 saved nothing (redirected to the index). Every date was in conflict." ;;
  '')                      die "step 3 did not redirect; it re-rendered, so nothing was saved." ;;
  *)                       die "step 3 redirected somewhere unexpected: $LOCATION" ;;
esac

LAST_ID="$(echo "$LOCATION" | command grep -o 'id=[0-9]*' | head -1 | command cut -d= -f2)"
echo "created $N_VALID allocation(s); last id $LAST_ID"
echo
echo "Find exactly these rows again (both ends bounded, no reliance on id arithmetic):"
echo "  SELECT id, application_id, from_, to_, cost FROM bb_allocation"
echo "   WHERE additional_invoice_information = '$TAG' ORDER BY id;"
echo
echo "ALLOCATION_TAG=$TAG"
echo "LAST_ALLOCATION_ID=$LAST_ID"
