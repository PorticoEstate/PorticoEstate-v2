# Issue #1066 — Findings & Root Cause Analysis

> GitHub: https://github.com/PorticoEstate/PorticoEstate-v2/issues/1066
> "Grundig testing av søknadsbehandling i Stage (Digdir template)"
>
> Investigated 2026-06-11 — code analysis + reproduced locally (Playwright against pe-api.test).
> Status legend: ✅ verified/reproduced · 🟡 mechanism verified, exact trigger needs data · ❓ open question

---

## Root-cause clusters

Before the per-finding list: three of the findings (7, 10, partly 8) share **one root cause**, and finding 9 has its own distinct cause. Fixing these two items resolves the worst of the list.

### Cluster A — `getRelatedApplications()` discrepancy (findings 7, 10, 8)

The new repository method treats a parent application without `parent_id` as a standalone application, while legacy expands children:

| | Legacy `get_related_applications()` | New `getRelatedApplications()` |
|---|---|---|
| File | `src/modules/booking/inc/class.soapplication.inc.php:1423-1458` | `src/modules/booking/repositories/ApplicationRepository.php:256-285` |
| App **has** `parent_id` | parent + all siblings | parent + all siblings ✅ same |
| App has **no** `parent_id` (is parent) | self + all children (`get_child_applications`) | **only self** ❌ |

Such groups exist in real data (parent `parent_id = NULL`, children pointing at it). Local example reproducing the exact stage pattern: **#67036 (parent, ACCEPTED) / #67037 (child, NEW)**. The new view page (`/booking/view/applications/67036`) shows the parent as a standalone application — the child is invisible, no "Related applications" count.

Consequences when admin works from the parent's page:
- **Assign to me** → children never get `case_officer_id` → legacy *Endre* on a child crashes (finding 10)
- **Accept** → children never get status updated → stuck at NEW (finding 7)
- Documents attached to a sibling are unreachable (finding 8 amplification)

Additional data wrinkle: checkout sets `parent_id = NULL` for **recurring** applications ("processed individually" — `src/modules/bookingfrontend/services/applications/ApplicationService.php:682,700,721`), so recurring apps in a combined order are never part of the parent_id group at all.

### Cluster B — stale toolbar state (finding 9)

`num_associations` is computed **once at page load** (`src/modules/booking/controllers/ApplicationController.php:99-104`) and baked into the Actions menu (`application_show.js:380-381`). Creating tildelinger in another tab/flow does not refresh it — the approve item stays disabled until a full reload. Reproduced: inserted an association in DB while page was open → still disabled; reload → enabled.

---

## Findings

### 1. ✅ Artikler vises ikke i checkout (Portal)

**Reproduced:** created application #83740 with 2× "Prosjektor 4k" (à 100 kr). Checkout showed only the aggregate row "Kultursalen … 200 kr" — the article cost is included in the sum but **never itemized**.

- Cart table: `src/modules/bookingfrontend/client/src/components/layout/header/shopping-cart/shopping-cart-table.tsx` — renders only `calculateApplicationCost(item)`, never iterates `application.orders[].lines[]`
- A working line-item renderer already exists: `CartCBreakdown.tsx` (cart-c components)

**Fix direction:** render order lines per application in the checkout table (reuse the breakdown component). *Note: tester says it's missing in Test (prod) too — likely never implemented, not a regression.*

**✔ FIXED (2026-06-11):** dedicated "Tileggstjenester" section in checkout (styled like the Bevertning section), grouped by application title:
- New `components/checkout/articles/articles-section.tsx` + `.module.scss` — one table (Artikkel | Mengde | Sum), bold group row per application, section total; hidden when no articles
- Wired into `cart-section.tsx` for both building groups and the recurring section
- Add-on articles identified via `article_cat_id === 2` (NOT `parent_mapping_id`, which is only set when the client sends `parent_id` at order time — unreliable)
- `article_cat_id` exposed through the whole chain: PHP `booking/models/OrderLine.php` + `bookingfrontend/repositories/ApplicationRepository.php::fetchOrders()` SQL, node ws `application.service.ts` SQL + `order-line.dto.ts`, client `IOrderLine` type
- Side discovery: `IOrderLine` numeric fields (`amount`, `tax`, `quantity`) arrive as **strings** from the API despite the TS type — coerced in the component; worth fixing at the source eventually
- Side discovery: websocket is the primary source for cart partials (REST is fallback) — backend serialization changes must be applied in **both** PHP and `src/WebSocket/node/` (+ image rebuild)

---

### 2. Artikkeltabellen i søknadsdialogen (Portal)

#### 2a/2b 🟡 Column widths (price wraps, quantity "100")

- No `white-space: nowrap` / `min-width` on price/quantity cells in `src/modules/bookingfrontend/client/src/components/article-table/article-table.module.scss`
- Not reproducible at desktop width with small amounts; the missing constraints are real and will wrap for `10 000,50 kr`-style values in the narrow modal

**Fix direction:** `white-space: nowrap` + sensible min-widths on the numeric columns.

#### 2c ✅ Period instead of Norwegian number format

**Reproduced in both portal and admin:**
- Portal dialog: `156.25 kr`, `375.00 kr` (`article-table.tsx:371,373,402,464,483` — raw values / `toFixed(2)`)
- Admin Purchase orders table: `136.99`, `2268.62` (application_show.js)
- A correct util **already exists and is unused here**: `formatCurrency()` in `src/modules/bookingfrontend/client/src/utils/cost-utils.ts:15-22` (`Intl.NumberFormat('nb-NO')` → `1 712,37 kr`)

**Fix direction:** use `formatCurrency()` in article-table.tsx; add an equivalent formatter in application_show.js for admin.

#### Bonus 🎁 Missing translations

`common.decrease` / `common.increase` render as raw keys on the ± buttons in the article table.

---

### 3. ✅ Sesong-edge-case: gjentakende over to sesonger (Portal)

**Reproduced with exact boundary proof.** Building 10 has season ending **26.09.2029** and a following season (Høst 2030, active+published). In the "Gjenta til dato" picker:
- Sept 2029: days 20–26 selectable, **27–30 disabled**
- All of Oct 2030 (inside the next season): **disabled**

**Cause:** `src/modules/bookingfrontend/client/src/components/building-calendar/modules/event/edit/application-crud.tsx`
- `:553-557` — `maxRepeatUntilDate = end of the season containing the start date`
- `:1534-1541` — enforced via `maxDate` + clamping in `onDateChange`
- No backend validation either (`bookingfrontend/controllers/applications/ApplicationController.php:789-797` accepts `recurring_info` unchecked)

**To discuss (product):** should repeat-until extend into a directly adjoining season? Into any future published season (with gaps skipped)? This is a behavior decision, not just a fix.

---

### 4. ✅ Fakturainformasjon vises to ganger (Admin)

**Reproduced** on `/booking/view/applications/83734` (privatperson/ssn): two consecutive sections both titled "Invoice information" — one with masked SSN, one with street/zip/city.

**Cause:** `src/modules/booking/html/application/show/application_show.js:629-644` — the ssn branch renders an invoice section, and the address block below it **always** renders a second invoice section (not in the if/else chain). Only happens for `customer_identifier_type === 'ssn'`; org-number applications show one section.

**Fix direction:** merge the SSN field into the single invoice/address section.

---

### 5. ✅ Deltakere: mann/kvinne vs. bare antall (Admin)

**Confirmed visually** — `renderAgTable()` (`application_show.js:678-692`) renders Name/Male/Female columns per age group.

**To discuss (product):** tester suggests showing only a total count. Not a bug — one-function change once decided. (Worth checking whether the male/female split is still meaningful data anywhere — the frontend form does collect it per gender.)

---

### 6. ✅ Lite luft mellom label og tekstbox (Admin, modaler)

**Reproduced and measured:** label→textarea gap in the Reply modal is **0px** — label sits flush against the input border.

**Cause:** modal form fields use `.ds-field` (`application_show.js` ~1410/1440/1470), but the field renders as `display:block` with `gap:normal` — Designsystemet's field layout CSS (flex column + gap) is **not loaded/applied** on this page. The page's own CSS (`application_show.css:457`) only sets margin *between* fields.

**Fix direction:** add the missing field layout (e.g. `.app-show__dialog .ds-field { display:flex; flex-direction:column; gap:var(--ds-size-2, .5rem); }`) — or ensure the relevant designsystemet CSS is included.

---

### 7. ✅ Søknad står som NEW etter godkjenning (Admin) — Cluster A

**Confirmed via local data:** group **67036 (parent, ACCEPTED, `parent_id=NULL`) / 67037 (child, NEW)** — the exact stage pattern (#77817). The new view page shows 67036 as standalone; the child is invisible to assign/accept propagation.

**Cause:** `ApplicationRepository::getRelatedApplications()` (`:263-266`) returns only `[id]` when the app has no `parent_id` — legacy expands children. See Cluster A.

Note: the new `acceptApplication()` (`booking/services/ApplicationService.php:233-309`) sets related apps to ACCEPTED (has associations) or auto-REJECTED (none). An app left at **NEW** therefore proves it wasn't in the related set at accept time.

**Fix direction:** port the legacy child-expansion into the new `getRelatedApplications()` (when no `parent_id`: `SELECT id FROM bb_application WHERE parent_id = :id`). Also consider whether recurring apps' `parent_id = NULL` at checkout is intended (see Cluster A wrinkle).

**Open question ❓:** stage DB check would confirm which variant hit #77816–77818:
```sql
SELECT id, parent_id, status, case_officer_id, recurring_info IS NOT NULL AS recurring
FROM bb_application WHERE id IN (77816, 77817, 77818);
```

---

### 8. 🟡 Vedlegg finnes ikke igjen (Admin) — Cluster A-adjacent

**Mechanism verified in code (not browser-tested):**
- The page fetches `GET /booking/applications/{id}/documents` (`application_show.js:223`)
- `fetchDocuments()` (`ApplicationRepository.php:482-491`) queries `bb_document_application WHERE owner_id = <viewed id>` **only**
- Dates and associations on the same page aggregate across the related group — documents do not

If the attachment was uploaded on a sibling partial application (or the group is invisible per Cluster A), it never shows.

**Fix direction:** aggregate documents across `getRelatedApplications()` ids like the other sections (after fixing Cluster A).

---

### 9. ✅ Godkjenn-knapp disabled på gjentakende tross tildelinger (Admin) — Cluster B

**Reproduced exact message** ("One or more bookings, allocations or events needs to be created before an application can be accepted") and **demonstrated the mechanism**:
1. Page open with 0 associations → approve item disabled
2. Association created in DB (simulating tildeling made in another tab) → **still disabled** (no refresh)
3. Reload → approve enabled

- Disable condition: `application_show.js:380-381` (`tb.num_associations === 0`)
- Computed once at load: `ApplicationController.php:99-104`
- `bb_application_association` is a **DB view** over bb_booking/bb_allocation/bb_event `WHERE application_id IS NOT NULL` — so allocations created without `application_id` set also won't count (possible secondary cause on stage)

Tester's "klikk Vis → godkjenn fungerer" = the navigation forced a reload.

**Fix direction:** re-fetch toolbar state (or at least `num_associations`) when the page regains focus / after in-page create actions; optionally verify the allocation-creation flow sets `application_id`.

---

### 10. ✅ Krasj «current user is not assigned» ved Endre (Admin) — Cluster A

**Reproduced identically** (same exception, same stack frame) on `/?menuaction=booking.uiapplication.edit&id=67433&selected_app_id=67433&hide_invoicing=1`:

```
#0 src/modules/booking/inc/class.uiapplication.inc.php(3093):
   booking_uiapplication->check_application_assigned_to_current_user(Array)
```

**Cause chain:**
1. `edit()` (`class.uiapplication.inc.php:3054-3093`) loads the sub-app via `selected_app_id` and requires `case_officer_id == current user` (`:264-272`)
2. The sub-app never got the case officer because assign-propagation missed it (Cluster A)

**Fix direction:** primary fix is Cluster A. Defense-in-depth option: in `edit()`, accept assignment held on *any* application in the combined group (or auto-assign the sub-app when the user is CO on the group), so old broken data doesn't keep crashing.

---

## Bonus findings (not in the issue)

| What | Where |
|---|---|
| `common.decrease` / `common.increase` shown as raw keys | Portal article table ± buttons |
| `bookingfrontend.building_parent_constraint_note` shown as raw key | Checkout, group heading description |
| Admin Purchase orders uses period decimals too | `/booking/view/applications/{id}` Purchase orders section |
| `DELETE /bookingfrontend/applications/partials/{id}` returned 200 but did not delete the row | Had to clean up manually in DB — verify route/soft-delete behavior |

---

## Suggested fix order (for discussion)

1. **Cluster A** — `getRelatedApplications()` child expansion (fixes 7, 10, unblocks 8) — small, high impact
2. **#4** duplicate invoice section — trivial
3. **#2c** number formatting (portal + admin) — small
4. **#6** ds-field spacing — small CSS
5. **#8** aggregate documents across group — small, after (1)
6. **#9** toolbar refresh — medium
7. **#1** article lines in checkout — medium (component work)
8. **#2a/2b** column widths — small CSS
9. **#3** season span — needs product decision first
10. **#5** participants display — needs product decision first

## Test data used locally (cleaned up)

- App #83740 created via API for checkout test → hard-deleted (incl. child rows)
- Allocation #497767 temporarily linked to app #67404 → reverted to NULL
