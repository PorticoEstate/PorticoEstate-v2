# PE13638 — network recording: user-side REPEATING application, create → checkout

Recorded 2026-08-18, 12:27–12:32 UTC (host clock is CEST, +02:00), by driving the
operator's steps in a real browser with a Playwright `page.on('request'/'response'/'websocket')`
recorder installed **before** the first navigation.

- Engine: **Chromium 151.0.7922.138** (`browser.browserType().name()` / `browser.version()`
  off the live object), UA `Chrome/151.0.0.0`. Not the Firefox fallback.
- Base: `https://pe-api.test`, self-signed cert.
- Session cookie: `bookingfrontendsession`; session id observed `37cbfb8fb239883068476828ad1d4b5b`.
- Fixture produced by this recording: **application 83967**, date row 629325, order 18026.

The builder that replays this without a browser:
`tests/e2e/pe13638-build-recurring-application.sh`.

---

## Which backend served the create — settled from this recording

**PHP, over HTTP.** `POST /bookingfrontend/applications/partials` → `201`.

The socket.io connection (`/wss/socket.io/`, engine.io v4, polling then websocket
upgrade) was live throughout. Across the **whole** session its client→server frames were,
in full: `2probe`, `5`, engine.io heartbeat `3`s, and exactly one application-level frame —

```
42["message",{"type":"update_session","sessionId":"37cbfb8fb239883068476828ad1d4b5b",...}]
```

There is **no** create/checkout frame in the SENT direction. What the socket carries is
the aftermath, RECV only:

```
42["message",{"type":"partial_applications_response","data":{... "applications":[{"id":83967,"status":"NEWPARTIAL1",...}]}}]   ← after the create
42["message",{"type":"server_message","action":"new","messages":[{"type":"success","text":"Søknaden har blitt sendt inn ..."}]}]  ← after the checkout
42["message",{"type":"partial_applications_response","data":{"applications":[],"count":0,...}}]                                  ← cart now empty
```

So this is not the "no POST appeared ⇒ it went over the socket" case. The POST *did*
appear, with the full body, and the socket only mirrored the resulting cart state.
(Frames labelled `{"event":"ping","tree":...}` in the raw capture are the Next.js dev
HMR socket, a different connection entirely — do not mistake them for socket.io.)

---

## The three calls that constitute the flow

Everything else in the capture is read-only lookup or Next.js RSC noise.

### 1. Login — `GET /bookingfrontend/login/?after=%2Fclient%2Fno%2Fresource%2F482` → `302`

The operator's two clicks ("Logg inn" → "Privatperson") are one plain GET. No credentials
are posted; this test server auto-binds the SSN test user. Response sets:

```
Set-Cookie: bookingfrontendsession=<32 hex>; Max-Age=14400; path=/; domain=pe-api.test; SameSite=Lax
Set-Cookie: domain=default
Set-Cookie: login_as_organization=1
Set-Cookie: after=%22%5C%2Fclient%5C%2Fno%5C%2Fresource%5C%2F482%22
Location: /bookingfrontend/client/no/resource/482?rid=...&click_history=...
```

⚠️ **Replaying this call cold does not log you in.** It binds a user onto an *existing*
bookingfrontend session; it does not create one. Called first-thing from curl it still
returns 302 and still hands out a `bookingfrontendsession` cookie — and every subsequent
call answers `{"error":"Not authenticated"}`. `GET /bookingfrontend/user/session` must come
first. In the browser that ordering is accidental: the client polls `/user/session` on load.
Measured both arms; see the builder's step 1 comment.

Verify with `GET /bookingfrontend/user` → `{"id":9669,"name":"Henning Berge","org_id":79,
"orgnr":"994239929","is_logged_in":true,"delegates":[{org_id:6972,...},{org_id:79,...}], ...}`.

### 2. Create the partial — `POST /bookingfrontend/applications/partials` → `201`

Request body, verbatim from the capture:

```json
{
  "building_name": "Fana kulturhus",
  "building_id": 10,
  "dates": [{"from_": "2026-08-19T13:00:00.000Z", "to_": "2026-08-19T13:30:00.000Z"}],
  "audience": [7],
  "agegroups": [
    {"agegroup_id": 2, "male": 0,  "female": 0},
    {"agegroup_id": 4, "male": 0,  "female": 0},
    {"agegroup_id": 6, "male": 12, "female": 0},
    {"agegroup_id": 5, "male": 0,  "female": 0}
  ],
  "articles": [{"id": 700, "quantity": 1, "parent_id": null}],
  "organizer": "Henning Berge",
  "name": "PE13638 gjentakende fixture",
  "homepage": "", "description": "", "equipment": "",
  "resources": [482],
  "activity_id": 1,
  "recurring_info": {"repeat_until": "2026-09-23", "field_interval": 1, "outseason": false},
  "customer_identifier_type": "organization_number",
  "customer_organization_id": 79,
  "customer_organization_number": "994239929",
  "customer_organization_name": "Bølleball forening"
}
```

Response: `{"id":83967,"message":"Partial application created successfully"}`.

Notes on the body:
- Times are **UTC instants**. `13:00Z` is the 15:00 local slot. The DB stores naive local
  (`bb_application_date.from_ = 2026-08-19 15:00:00`).
- `recurring_info` is written **here**, by the create. Checkout does not touch it.
- All four agegroups must be present; at least one `male > 0`.
- `articles` carries the resource's mandatory article (id 700 for resource 482, `mandatory: 1`
  from `GET /bookingfrontend/applications/articles?resources[]=482`). The builder looks this
  up rather than hardcoding it.
- `customer_organization_id: 79` is sent but **does not persist** —
  `bb_application.customer_organization_id` is NULL afterwards; only
  `customer_organization_number` survives. Recorded, not diagnosed.

### 3. Checkout — `POST /bookingfrontend/applications/partials/checkout` → `200`

Request body, verbatim:

```json
{
  "organizerName": "Henning Berge",
  "customerType": "ssn",
  "organizationNumber": "",
  "organizationName": "",
  "contactName": "Henning Berge",
  "contactEmail": "henning@grensesnitt.no",
  "contactPhone": "91113518",
  "street": "Røsslyngvegen 14",
  "zipCode": "4344",
  "city": "Bryne",
  "documentsRead": true,
  "building_parent_ids": {},
  "language": "no"
}
```

Response: `{"message":"Applications processed successfully","applications":[{"id":83967,
"status":"NEW", ...}]}` — i.e. `NEWPARTIAL1` → `NEW`.

Notes:
- "Agree to all Juridiske betingelser" is **not** per-document on the wire. Four checkboxes
  in the UI collapse to the single boolean `documentsRead`.
- `customerType:"ssn"` with blank organisation fields, on a booking whose create declared
  `organization_number` / org 79. The organisation that persists comes from the create.
  This matches the already-open checkout billing-misdirection ticket; reproduced as observed,
  not corrected.
- The client posts no application ids: checkout submits **the whole cart for this session**.
  A builder must therefore start from an empty cart, or it will submit someone else's leftovers.

---

## Read-only calls the page makes (context, not required by a builder)

`GET /bookingfrontend/user/session`, `/bookingfrontend/user`, `/user/messages`,
`/user/external-data`, `/notifications/unread-count`, `/lang/no`,
`/buildings/10/resources?results=-1`, `/buildings/10/seasons`, `/buildings/10/audience`,
`/buildings/10/agegroups`, `/buildings/10/schedule?dates[]=…`,
`/buildings/10/documents?type=regulation,HMS_document,price_list`,
`/resources/482/documents?type=…`, `/applications/articles?resources[]=482`,
`/applications/partials`, `/organizations/my`, `/api/server-settings?include_configs=true`,
`/checkout/external-payment-eligibility`, `/bookingfrontend/client/api/version`.

Two of these are worth keeping:

- `GET /bookingfrontend/checkout/external-payment-eligibility` →
  `{"eligible":false,"reason":"Gjentakende bookinger er ikke tilgjengelige for ekstern betaling",
  "total_amount":0,"payment_methods":[]}` — a recurring cart never reaches Vipps, so this
  flow has no payment leg at all.
- `GET /bookingfrontend/resources/document/1517/download` → **404** `{"error":"Document file not found"}`
  on the resource page, while the same document id 1517 is listed and checkbox-able at checkout.
  Pre-existing, unrelated to this flow, noted because it shows up in the capture as a console error.
