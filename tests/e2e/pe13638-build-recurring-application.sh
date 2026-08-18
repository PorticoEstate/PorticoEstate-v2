#!/usr/bin/env bash
#
# pe13638-build-recurring-application.sh
#
# NON-UI fixture builder: creates a *user-side recurring (repeating) application*
# on the local pe-api stack, end to end, with no browser.
#
# Built from a recorded browser run of the operator's steps (task #13638).
# The recording it replays lives beside this file:
#   tests/e2e/pe13638-network-recording.md
#
# It issues exactly the three calls the Next.js client issues, in the same order:
#   1. GET  /bookingfrontend/login/                      -> test-mode auto-login, sets bookingfrontendsession
#   2. POST /bookingfrontend/applications/partials       -> 201 {"id":N}  (status NEWPARTIAL1, recurring_info written)
#   3. POST /bookingfrontend/applications/partials/checkout -> 200        (status NEWPARTIAL1 -> NEW)
# plus read-only lookups so the script works for any of the 9 resources in the brief.
#
# The create is served by PHP over HTTP. The socket.io connection carries no
# create frame; it only receives a partial_applications_response broadcast
# afterwards. The builder therefore needs no websocket at all.
#
# Usage:
#   bash tests/e2e/pe13638-build-recurring-application.sh
#   RESOURCE_ID=590 REPEAT_WEEKS=6 bash tests/e2e/pe13638-build-recurring-application.sh
#
# Env (all optional):
#   RESOURCE_ID   default 482   one of 123 125 482 576 590 779 780 781 782
#   ORG_ID        default 79    Boelleball forening (994239929); must be one of the
#                               logged-in user's delegates -- see GET /bookingfrontend/organizations/my
#   START_DAYS    default: DERIVED PER RUN, 1..300 days from today -- see the
#                               derivation below. An explicit START_DAYS still wins.
#   START_HOUR    default 15    LOCAL hour; must be inside the resource opening hours
#   DURATION_MIN  default 30
#   REPEAT_WEEKS  default 5     weeks until recurring_info.repeat_until
#   INTERVAL      default 1     repeat every N weeks; the UI only offers 1..4
#   TITLE         default "PE13638 gjentakende fixture <timestamp>"
#   BASE          default https://pe-api.test
#   NO_CHECKOUT   set to 1 to stop after the partial is created (leaves it in the cart)
#
# Prints the created application id on the last line as: APPLICATION_ID=<n>
#
set -euo pipefail

BASE="${BASE:-https://pe-api.test}"
RESOURCE_ID="${RESOURCE_ID:-482}"
ORG_ID="${ORG_ID:-79}"

# ONE timestamp feeds BOTH the label and the day offset, so a second run in the
# same session moves its SLOT as well as its NAME. Before this, only the name
# moved and two runs on the same day requested the same slot.
STAMP="$(date +%Y%m%d-%H%M%S)"

# Day offset, derived from the seconds-of-day inside $STAMP. Two runs started D
# seconds apart get day offsets (D mod 300) apart, so back-to-back runs land on
# a different day AND a different weekday -- the weekday is what matters, because
# a recurring series occupies the same weekday for REPEAT_WEEKS weeks.
# 1..300 bounds the whole series at 300 + 5*7 = 335 days, inside the one-year
# user-facing window that pe13650 deliberately clears with its >= 365.
# RESIDUAL, documented rather than fixed: two runs separated by exactly 0, 7, 14,
# 21, 28 or 35 seconds modulo 300 reuse a weekday inside the repeat span and can
# share an occurrence date. A stateless timestamp cannot rule that out.
_HMS="${STAMP##*-}"
START_DAYS="${START_DAYS:-$(( 1 + (10#${_HMS:0:2} * 3600 + 10#${_HMS:2:2} * 60 + 10#${_HMS:4:2}) % 300 ))}"

START_HOUR="${START_HOUR:-15}"
DURATION_MIN="${DURATION_MIN:-30}"
REPEAT_WEEKS="${REPEAT_WEEKS:-5}"
INTERVAL="${INTERVAL:-1}"
TITLE="${TITLE:-PE13638 gjentakende fixture $STAMP}"

JAR="$(mktemp -t pe13638cookies.XXXXXX)"
trap 'rm -f "$JAR"' EXIT

# -k: the dev cert is self-signed. --fail-with-body so a 4xx/5xx aborts loudly.
C=(curl -sk --cookie-jar "$JAR" --cookie "$JAR")

die() { echo "FATAL: $*" >&2; exit 1; }
need() { command -v "$1" >/dev/null || die "missing dependency: $1"; }
need curl; need jq; need python3

# ---------------------------------------------------------------- 1. login ---
# The operator's step "Header Logg inn -> Privatperson" is this single GET.
# On this test server it auto-binds the SSN test user; no credentials are posted.
#
# ORDER MATTERS AND IS NOT OPTIONAL. /bookingfrontend/login/ binds the user onto
# an EXISTING bookingfrontend session; it does not create one. Called cold it
# still answers 302 and still hands out a bookingfrontendsession cookie, so it
# looks like it worked -- but that session is anonymous and every later call is
# "Not authenticated". Touch /bookingfrontend/user/session first. In the browser
# this happens by accident, because the client polls it on page load.
"${C[@]}" -o /dev/null "$BASE/bookingfrontend/user/session"
"${C[@]}" -o /dev/null "$BASE/bookingfrontend/login/?after=%2Fclient%2Fno%2Fresource%2F$RESOURCE_ID"

USER_JSON="$("${C[@]}" "$BASE/bookingfrontend/user")"
echo "$USER_JSON" | jq -e '.is_logged_in == true' >/dev/null \
  || die "login did not take. GET /bookingfrontend/user returned: $USER_JSON"

ORG_NAME="$(echo "$USER_JSON" | jq -r --argjson o "$ORG_ID" '.delegates[] | select(.org_id == $o) | .name')"
ORG_NR="$(echo "$USER_JSON"   | jq -r --argjson o "$ORG_ID" '.delegates[] | select(.org_id == $o) | .organization_number')"
[ -n "$ORG_NAME" ] && [ "$ORG_NAME" != "null" ] \
  || die "org $ORG_ID is not a delegate of this user. delegates: $(echo "$USER_JSON" | jq -c '.delegates')"

echo "logged in as $(echo "$USER_JSON" | jq -r .name) (bookinguser $(echo "$USER_JSON" | jq -r .id)) org=$ORG_NAME ($ORG_NR)"

# --------------------------------------------------- 2. reference lookups ---
RESOURCE_JSON="$("${C[@]}" "$BASE/bookingfrontend/resources/$RESOURCE_ID")"
BUILDING_ID="$(echo "$RESOURCE_JSON" | jq -r '.building_id // .results[0].building_id // empty')"
ACTIVITY_ID="$(echo "$RESOURCE_JSON" | jq -r '.activity_id // .results[0].activity_id // 1')"
[ -n "$BUILDING_ID" ] || die "could not resolve building_id for resource $RESOURCE_ID from: $(echo "$RESOURCE_JSON" | head -c 300)"

BUILDING_NAME="$("${C[@]}" "$BASE/bookingfrontend/buildings/$BUILDING_ID" | jq -r '.name // empty')"
[ -n "$BUILDING_NAME" ] || die "could not resolve building name for building $BUILDING_ID"

# Audience: pick the first active one, like "select any" in the steps.
AUDIENCE_ID="$("${C[@]}" "$BASE/bookingfrontend/buildings/$BUILDING_ID/audience" | jq -r '[.[] | select(.active == 1)][0].id')"
[ "$AUDIENCE_ID" != "null" ] || die "no active audience for building $BUILDING_ID"

# Agegroups: every group must be present in the payload; put the participant
# count on the LAST one so the "at least one > 0" rule is satisfied.
AGEGROUPS="$("${C[@]}" "$BASE/bookingfrontend/buildings/$BUILDING_ID/agegroups" \
  | jq -c '[.[] | select(.active == 1) | {agegroup_id: .id, male: 0, female: 0}]
           | if length == 0 then error("no active agegroups") else . end
           | .[-1].male = 12')"

# Articles: the resource's mandatory article(s) must be ordered, exactly as the
# modal pre-selects them. Anything non-mandatory stays at quantity 0.
ARTICLES="$("${C[@]}" "$BASE/bookingfrontend/applications/articles?resources%5B%5D=$RESOURCE_ID" \
  | jq -c '[.[] | select(.mandatory == 1) | {id: .id, quantity: 1, parent_id: (.parent_id // null)}]')"
[ "$ARTICLES" != "[]" ] || echo "note: resource $RESOURCE_ID has no mandatory articles; sending an empty articles list"

# ---------------------------------------------------------------- 3. dates ---
# The client sends UTC instants. START_HOUR is a LOCAL wall-clock hour, because
# that is what the opening-hours check compares against.
read -r FROM_UTC TO_UTC REPEAT_UNTIL <<EOF
$(python3 - "$START_DAYS" "$START_HOUR" "$DURATION_MIN" "$REPEAT_WEEKS" <<'PY'
import sys, datetime
days, hour, dur, weeks = (int(x) for x in sys.argv[1:5])
start_local = (datetime.datetime.now().astimezone() + datetime.timedelta(days=days)) \
    .replace(hour=hour, minute=0, second=0, microsecond=0)
end_local = start_local + datetime.timedelta(minutes=dur)
fmt = lambda d: d.astimezone(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%S.000Z')
print(fmt(start_local), fmt(end_local),
      (start_local + datetime.timedelta(weeks=weeks)).strftime('%Y-%m-%d'))
PY
)
EOF

echo "START_DAYS=${START_DAYS} (offset from today)"
echo "slot ${FROM_UTC} -> ${TO_UTC} (UTC), repeating every ${INTERVAL} week(s) until ${REPEAT_UNTIL}"

# ------------------------------------------------- 4. create the partial ----
# Body shape copied verbatim from the recorded POST. Only the values vary.
CREATE_BODY="$(jq -n \
  --arg building_name "$BUILDING_NAME" --argjson building_id "$BUILDING_ID" \
  --arg from "$FROM_UTC" --arg to "$TO_UTC" \
  --argjson audience "$AUDIENCE_ID" --argjson agegroups "$AGEGROUPS" --argjson articles "$ARTICLES" \
  --arg organizer "$(echo "$USER_JSON" | jq -r .name)" --arg name "$TITLE" \
  --argjson resource "$RESOURCE_ID" --argjson activity_id "$ACTIVITY_ID" \
  --arg repeat_until "$REPEAT_UNTIL" --argjson interval "$INTERVAL" \
  --argjson org_id "$ORG_ID" --arg org_nr "$ORG_NR" --arg org_name "$ORG_NAME" '
  {
    building_name: $building_name, building_id: $building_id,
    dates: [{from_: $from, to_: $to}],
    audience: [$audience],
    agegroups: $agegroups,
    articles: $articles,
    organizer: $organizer,
    name: $name,
    homepage: "", description: "", equipment: "",
    resources: [$resource],
    activity_id: $activity_id,
    recurring_info: {repeat_until: $repeat_until, field_interval: $interval, outseason: false},
    customer_identifier_type: "organization_number",
    customer_organization_id: $org_id,
    customer_organization_number: $org_nr,
    customer_organization_name: $org_name
  }')"

CREATE_RES="$("${C[@]}" -w '\n%{http_code}' -X POST \
  -H 'Content-Type: application/json' \
  --data-binary "$CREATE_BODY" \
  "$BASE/bookingfrontend/applications/partials")"
CREATE_CODE="$(echo "$CREATE_RES" | tail -n1)"
CREATE_BODY_OUT="$(echo "$CREATE_RES" | sed '$d')"
[ "$CREATE_CODE" = "201" ] || die "create returned $CREATE_CODE: $CREATE_BODY_OUT"

APP_ID="$(echo "$CREATE_BODY_OUT" | jq -r .id)"
echo "created partial application $APP_ID (status NEWPARTIAL1)"

if [ "${NO_CHECKOUT:-0}" = "1" ]; then
  echo "NO_CHECKOUT=1, stopping with the partial still in the cart"
  echo "APPLICATION_ID=$APP_ID"
  exit 0
fi

# ------------------------------------------------------------ 5. checkout ---
# "agree to all Juridiske betingelser" is not a per-document flag on the wire:
# the client collapses every checkbox into the single boolean documentsRead.
#
# NOTE, recorded not invented: the client posts customerType "ssn" with blank
# organizationNumber/-Name even for an organisation booking. The organisation
# actually persisted comes from the CREATE call above, not from here. This is
# the known open billing-misdirection defect; the body is reproduced as observed.
CHECKOUT_BODY="$(echo "$USER_JSON" | jq -c '{
  organizerName: .name, customerType: "ssn", organizationNumber: "", organizationName: "",
  contactName: .name, contactEmail: .email, contactPhone: .phone,
  street: .street, zipCode: .zip_code, city: .city,
  documentsRead: true, building_parent_ids: {}, language: "no"
}')"

CO_RES="$("${C[@]}" -w '\n%{http_code}' -X POST \
  -H 'Content-Type: application/json' \
  --data-binary "$CHECKOUT_BODY" \
  "$BASE/bookingfrontend/applications/partials/checkout")"
CO_CODE="$(echo "$CO_RES" | tail -n1)"
CO_BODY="$(echo "$CO_RES" | sed '$d')"
[ "$CO_CODE" = "200" ] || die "checkout returned $CO_CODE: $CO_BODY"

STATUS="$(echo "$CO_BODY" | jq -r --argjson id "$APP_ID" '.applications[] | select(.id == $id) | .status')"
[ "$STATUS" = "NEW" ] || die "checkout succeeded but application $APP_ID has status '$STATUS', expected NEW"

echo "checked out: application $APP_ID is now status NEW"
echo "APPLICATION_ID=$APP_ID"
