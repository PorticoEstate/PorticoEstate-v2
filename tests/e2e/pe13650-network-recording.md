# PE13650 — network recording: ADMIN-side repeating ALLOCATION, add → preview → create

Recorded 2026-08-18, 12:45–12:52 UTC (host clock is CEST, +02:00; the DB container is UTC),
by driving the operator's steps in a real browser with a Playwright
`page.on('request'/'response'/'websocket')` recorder installed **before** the first navigation.

- Engine: **Chromium 151.0.7922.138** (`browser.browserType().name()` / `browser.version()`
  off the live object), UA `Chrome/151.0.0.0`. Not the Firefox fallback.
- Base: `https://pe-api.test`, self-signed cert.
- Session cookie: `sessionphpgwsessid` (admin), **not** `bookingfrontendsession`.
- Fixture produced by this recording: **allocations 497937–497942** (6 rows, `application_id` NULL).

The builder that replays this without a browser:
`tests/e2e/pe13650-build-recurring-allocation.sh`.

---

## Which backend served the create — settled from this recording

**PHP, over HTTP.** A plain `application/x-www-form-urlencoded` form POST to
`/?menuaction=booking.uiallocation.add` → `302`.

The admin UI opens **no websocket at all**. Across the whole session the recorder logged
**0** websocket events of any kind — no socket.io, no HMR socket, nothing.

> **Positive control for that zero, run in the same session on the same recorder:**
> `new WebSocket('ws://127.0.0.1:9/pe13650-recorder-control')` — chosen because it touches no
> app endpoint — was captured immediately as `OPEN` + `CLOSE`. So `page.on('websocket')` was
> live and the 0 is a true zero, not a dead handler.

This is a **stronger** result than the user-side flow in `pe13638-network-recording.md`, where a
socket.io connection existed and carried the aftermath (`partial_applications_response`) but no
create frame. Here there is no socket to carry anything. ⚠️ The Next.js dev HMR socket that
appears on the bookingfrontend client does **not** exist on these legacy admin pages.

---

## THE FLOW IS A THREE-STEP WIZARD, NOT ONE POST

`booking_uiallocation::add()` reads `step` from the request and increments it when validation
passes (`src/modules/booking/inc/class.uiallocation.inc.php:552-555`):

| POST carries | server step becomes | what happens |
|---|---|---|
| no `step` (defaults to 1) | 2 | renders the **preview** of valid/invalid dates. **Saves nothing.** |
| `step=2` | 3 | saves **one `bb_allocation` row per occurrence**, then 302s |

The recurring branch is entered by `repeat_until` being non-empty
(`Sanitizer::get_var('repeat_until','bool')`, :621) — **there is no "recurring" checkbox on
this form.** The first occurrence is created inside the same loop as the rest (:658-756), not by
the single-date `bo->add()` at :565.

⚠️ **A builder that posts once and stops has created nothing, and gets a `200` for it.**

---

## The three calls that constitute the flow

### 1. Login — `POST /login` → `200`

```
logindomain=default&login=henning&passwd=<see AUTH-DETAILS.md>
```

Sets `sessionphpgwsessid`. **Unlike the citizen login, this works cold** — no priming GET is
needed. (The user-side flow required `GET /bookingfrontend/user/session` first, because
`/bookingfrontend/login/` only *binds* a user onto an existing session.)

Arms measured:

| arm | `POST /login` | subsequent `?menuaction=booking.uiallocation.add` |
|---|---|---|
| correct password | `200` | `200`, 40551 bytes, contains `Ny tildeling` |
| **wrong password** | **`404`** | `302`, 0 bytes, no `Ny tildeling` |
| **no cookie jar** | — | `302`, 0 bytes, no `Ny tildeling` |

### 2. Step 1 → 2, the preview — `POST /?menuaction=booking.uiallocation.add` → `200`

Request body, verbatim from the capture (URL-decoded for readability):

```
tab=
application_id=                     ← EMPTY. This is what makes the rows parentless.
building_id=10
building_name=Fana kulturhus
organization_id=79
organization_name=Bølleball forening
from_=24/08-2027 15:00              ← format d/m-Y H:i, LOCAL wall clock
to_=24/08-2027 16:00
repeat_until=28/09-2027             ← format d/m-Y; non-empty ⇒ recurring branch
field_interval=1
season_id=1024
cost=0.00
resources[]=93
resources[]=123
selected_articles[]=718_1_9_0.00_null
selected_articles[]=                (×3 empty)
selected_articles[]=519_1_9_0.00_null
selected_articles[]=                (×3 empty)
additional_invoice_information=
```

Response is the preview page, carrying `step=2` and a `temp_id`, plus one
`<tr class="table-success">` per creatable date and one `<tr class="table-warning">` per
conflict. For this body: **6 valid, 0 in conflict** —
2027-08-24, 08-31, 09-07, 09-14, 09-21, 09-28, all 15:00–16:00.

### 3. Step 2 → 3, the create — `POST /?menuaction=booking.uiallocation.add` → `302`

Request body, verbatim:

```
tab=&organization_name=Bølleball forening&organization_id=79
&building_name=Fana kulturhus&building_id=10
&from_=24/08-2027 15:00&to_=24/08-2027 16:00&weekday=
&building_id=10&cost=0.00&season_id=1024&field_building_id=10
&step=2                              ← server increments to 3 and SAVES
&repeat_until=28/09-2027&field_interval=1&outseason=
&application_id=                     ← still empty
&temp_id=allocation_1787057440
&additional_invoice_information=&skip_bas=0
&resources[]=93&resources[]=123
&create=Lagre
```

Response:

```
302 → /?menuaction=booking.uiallocation.show&id=497942&click_history=0b69ec69b785264f714019a9a14d81f2
```

The redirect id is the **last** successful insert (`$last_successful_id`, :766), not the first.

Failure redirects, from source and worth distinguishing in a builder:
- every date in conflict ⇒ `302 → booking.uiallocation.index` (nothing saved)
- validation failed at step 1 ⇒ **no redirect at all**, the form re-renders with `200`

---

## The `click_history` question — ANSWERED: inert on this route

`click_history` is a repost-guard nonce: `md5($login . time())`, generated per session
(`Sessions::generate_click_history()`, `Sessions.php:1322`) and validated against
`Cache::session_get('phpgwapi','history')` by `phpgw::is_repost()` (`src/helpers/phpgw.php:298`).
So it **is** session-scoped in origin.

But `is_repost()` is **never called on this route.** It appears once in the whole `booking`
module, in `class.uiapplication.inc.php:1716` — not in `class.uiallocation.inc.php`.
(Positive control: the same `rg` found 1 hit in `src/helpers/phpgw.php`, so the search fired.)

Measured, one session, same route, four arms:

| arm | result |
|---|---|
| the operator's verbatim token `c2bba8cc…` (from *his* session, days old) | `200`, 40551 bytes |
| no `click_history` at all | `200`, **40551 bytes** |
| garbage token `deadbeef…` | `200`, **40551 bytes** |
| the verbatim token a second time (repost?) | `200`, **40551 bytes** |
| *control:* verbatim token, **no session** | `302`, 0 bytes |

Byte-identical. ⇒ **Drop it.** It is neither required nor validated here. The builder omits it.

---

## Lookup endpoints the form's own JS uses

From `src/modules/booking/js/base/allocation.js`. All take `&phpgw_return_as=json` and answer
`{data:[…]}` (not `results` / `ResultSet`).

```
?menuaction=booking.uibuilding.index&query=<term>
?menuaction=booking.uiorganization.index&filter_active=1&query=<term>
?menuaction=booking.uiseason.index&sort=name&filter_building_id=<id>&filter_now=1&length=-1
?menuaction=booking.uiresource.index&sort=name&filter_building_id=<id>&length=-1
```

Values resolved for the operator's steps:

- building **Fana kulturhus = 10** ⚠️ the query also returns **Fana kulturhus LINKEN (111)** —
  match exactly, or a builder picks the wrong building.
- organization **Bølleball forening = 79** (`994239929`)
- season **1024 "Fana Kulturhus"** (01/01-2026 → 26/09-2029). A second season
  **1028 "Fana Kulturhus Høst 2030"** also exists. Seasons come back as `d/m-Y`.
- resources for building 10: `482 576 125 93 123 92 779 780 781 782 106 590`.

---

## ⚠️ Instrument limitation hit during this run — NAMED, not worked around silently

**Playwright's synthetic mouse and keyboard were not delivered to these legacy admin pages** in
this session, under Chromium 151.0.7922.138. They worked on `/login_ui` and stopped working on
`?menuaction=…`.

Evidence, all in-session:
- `pressSequentially` into `#field_building_name` left the value `""`, and a listener on the
  element logged **zero** `keydown`/`input`/`keyup` events. Control: the same call into
  `#field_cost` also failed ⇒ not field-specific.
- `locator(...).click()` on the submit button fired **none** of four listeners bound to the
  button and the form — including a capture-phase listener on the button itself.
- The element was not the problem: `elementFromPoint` at the button's centre returned **the
  button**, `disabled=false`, `visibility:visible`.
- **Control that separates instrument from application:** an in-page
  `document.querySelector(...).click()` fired all four listeners with
  `defaultPrevented=false`, and the form submitted normally.

⇒ The application is fine. What could not be measured this way is **the real pointer path** —
whether a human's click is intercepted by anything. That is unmeasured, not cleared.

`fill()` and in-page `.click()` both work, and the builder needs neither.

---

## Read-only calls the page makes (context, not required by a builder)

`?menuaction=booking.uiallocation.index&phpgw_return_as=json` (datatable), the four lookups
above, `?menuaction=booking.uiarticle*` for the articles table, plus the usual legacy asset
fan-out (jquery-ui, DataTables3, jqtree, contextMenu, responsive-tabs).
