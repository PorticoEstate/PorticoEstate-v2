# REST Client Migration Playbook

This document is an implementation guide for migrating legacy `menuaction`-driven UI modules to client-side communication with REST APIs, based on the phased modernization plan and lessons learned from converting `property.uientity`.

## Purpose

Use this playbook when migrating modules such as:

- `uitts`
- `uiproject`
- `uiworkorder`
- `uirequest`

The objective is to keep existing XSL-rendered page shells while progressively moving data exchange and write paths to REST endpoints.

## Generic vs Module-Specific Guidance

This playbook is the generic baseline for all legacy-to-REST client migrations.

- Keep generic rules here: architecture, phase sequence, DB conventions, naming, testing, and rollout gates.
- Keep module-specific behavior in separate companion documents under `doc/`.
- Link each module migration to one dedicated companion document and one contract matrix/checklist.

Companion example:

- `doc/location_rest_client_migration.md` (Location-specific migration decisions)
- `doc/uilocation_phase_a_contract_matrix.md` (Location contract freeze + parity tracking)

## Core Strategy

Adopt a **Strangler Fig** approach:

1. Keep existing page rendering and navigation (`menuaction`, XSL, ACL frame).
2. Replace AJAX data calls with REST endpoints, one surface at a time.
3. Move write flows (`save`, `delete`, file operations) to REST after parity validation.
4. Remove legacy handlers only when references and usage are gone.

Do not do a big-bang rewrite.

## Architecture Target

### Keep (for now)

- Legacy page shell routes (`index`, `edit`, `view`) for rendering.
- Existing XSL templates and JS widgets.
- Existing ACL and session context bootstrap done by legacy UI classes.

### Replace incrementally

- Data fetch URLs in client JS (`menuaction` -> REST URL).
- Save/update/delete interactions (`POST/PUT/DELETE` to REST).
- Legacy internal orchestration in UI classes with shared helper/service code.

## Migration Preconditions

Before starting a module migration:

1. REST controller exists for the domain surface (or can be added in parallel).
2. Endpoint ACL behavior matches legacy behavior.
3. OpenAPI annotations exist and are generated successfully.
4. You can run syntax checks and basic tests in the target environment.

## Database Access Conventions

Use these rules in REST migrations to reduce SQL-injection risk and keep result handling consistent.

### Prepared statements first

- Prefer prepared queries for all dynamic SQL (filters, ids, search strings, date ranges, paging).
- Avoid concatenating untrusted input into SQL strings.
- Keep named placeholders explicit and typed in caller code when relevant.

Preferred patterns:

- `Db::prepare()` + `execute()` for one-off lookups and writes.
- `Db::limit_query_with_params()` for paginated reads with named placeholders.

### Use `Db::limit_query_with_params()` for REST list endpoints

`Db::limit_query_with_params()` should be the default in REST list/read paths that currently use `limit_query()` plus interpolated filters.

- Signature: `Db::limit_query_with_params($sql, array $params = [], $offset = 0, $line = '', $file = '', $num_rows = 0)`
- Use `$params` with named placeholders (for example `:id`, `:query`, `:location_id`).
- Use `$num_rows = null` when you intentionally want no `LIMIT` clause (for example count wrappers or full export flows).

Example:

```php
$sql = "SELECT id, title FROM fm_request WHERE location_id = :location_id AND title ILIKE :query ORDER BY id DESC";
$params = [
    ':location_id' => (int)$locationId,
    ':query' => '%' . $query . '%',
];
$this->db->limit_query_with_params($sql, $params, $start, __LINE__, __FILE__, $results);
```

### `DbRowTrait` guidance

`DbRowTrait` is a row-value decode helper, not a query builder and not a security feature.

- Use `DbRowTrait::dbStrip()` when migrating cursor-style reads (for example replacements of `$this->db->f($col, true)` patterns).
- Keep decoding concerns separate from query construction.
- Do not treat `dbStrip()` as input sanitization for SQL.

### Review gate for DB access

Before merging, verify:

1. Dynamic SQL in new REST code uses prepared statements.
2. Paginated reads use `Db::limit_query_with_params()` where practical.
3. Parameter arrays and placeholder names match exactly.
4. `DbRowTrait` is used only for output/value decoding compatibility.

## Generic Layout and Naming Conventions

Yes, this should be explicit in the playbook. Consistent naming and placement reduce migration variance and make review/testing easier across modules.

### Recommended module layout

For each domain module, keep this structure predictable:

- UI adapters: `src/modules/{app}/inc/class.ui{module}.inc.php`
- REST controllers: `src/modules/{app}/controllers/{Module}Controller.php`
- Shared workflow helpers/services: `src/modules/{app}/helpers/{Module}FormHelper.php` (or `...Service.php`)
- Optional domain services: `src/modules/{app}/services/{Module}{Action}Service.php`
- Client JS entry points: `src/modules/{app}/js/base/{module}.edit.js`, `...{module}.index.js`
- Route registration: `src/modules/{app}/routes/...`

### Controller naming rules

- Use resource-focused names, not transport/action names.
- Preferred: `{DomainNoun}Controller`.
- Avoid: `SaveController`, `AjaxController`, `LegacyController` for new REST surfaces.

Examples:

- `EntityController`
- `ProjectController`
- `RequestController`
- `WorkOrderController`

### Helper/service naming rules

- Put shared write orchestration in one helper/service used by both legacy UI and REST controller.
- Name by domain + intent:
	- `{DomainNoun}FormHelper` for form shaping, mapping, and save orchestration.
	- `{DomainNoun}ValidationService` for reusable validation.
	- `{DomainNoun}RelationMapper` for payload context mapping.

Do not let REST controllers and legacy UI classes each maintain separate save pipelines.

### Method naming guidance

- Legacy UI adapter methods can keep existing names (`save`, `edit`, `index`) for compatibility.
- REST controller methods should be transport-agnostic in internals:
	- `index`, `show`, `store`, `update`, `destroy`
	- sub-resources: `getFiles`, `addFile`, `deleteFile`, `getRelated`, etc.

### Route and payload naming guidance

- Keep route paths noun-based and stable.
- Keep payload top-level elements explicit and domain-semantic (for example `RelationInfo`).
- Avoid introducing temporary names that encode migration phase.

### Review gate for naming/layout

Before merging, verify:

1. New controller/helper file names follow the conventions above.
2. Shared workflow code exists in one place and is reused.
3. JS filenames and bootstrap payload keys are consistent with domain naming.
4. OpenAPI operation tags and schema names match controller/domain names.

## Phase Plan Per Module

## Phase A: Contract Freeze

Document current behavior before changing anything.

### Freeze these contracts

- `index()` data contract used by DataTables.
- `edit()`/`view()` bootstrap payload used by JS and XSL.
- `save()` result contract (JSON and redirect branches).
- Sub-resource endpoints used by tabs (files, related, inventory, etc.).

### Why this matters

Most regressions come from undocumented implicit contracts.

## Phase B: REST Endpoint Surface

Implement REST endpoints in parallel, not replacement-first.

### Required qualities

- Exact ACL parity with legacy methods.
- Stable route naming by resource shape, not UI action naming.
- Response payloads that are client-consumable without HTML coupling.

### Recommended route shape

- Collection: `GET /resource/{type}/{entity_id}/{cat_id}`
- Item: `GET /resource/{type}/{entity_id}/{cat_id}/{id}`
- Create: `POST /resource/{type}/{entity_id}/{cat_id}/create`
- Update: `PUT /resource/{type}/{entity_id}/{cat_id}/{id}`
- Delete: `DELETE /resource/{type}/{entity_id}/{cat_id}/{id}`
- Sub-resources: explicit paths (`/files`, `/related`, `/inventory`, etc.)

## Phase C: Shared Workflow Extraction

Move business workflow out of UI classes into shared helpers/services.

### Patterns that worked

- Keep legacy UI method as thin adapter.
- Use one shared helper for both legacy `save()` and REST `store()/update()`.
- Reuse the same validation, persistence, checklist, file, and receipt handling.

### Guardrail

Never duplicate save logic in controller and legacy class separately.

## Phase D: Client Cutover (Read Paths First)

Update JS to use REST data endpoints while keeping page rendering unchanged.

### Steps

1. Replace DataTable `requestUrl` with REST URL.
2. Replace per-tab AJAX calls (`get_files`, `get_related`, etc.) with REST URLs.
3. Keep progressive fallback to legacy behavior if bootstrap data is missing.

### Bootstrapping recommendation

Emit endpoint URLs and mode flags as page bootstrap data from server-side payload.
Do not hardcode host/module paths in JS.

## Phase E: Client Cutover (Write Paths)

Move `apply`/`save`/`delete` actions to REST.

### Required behavior parity

- Preserve apply/save semantics (stay on page vs redirect).
- Preserve message/receipt rendering behavior.
- Preserve upload behavior (multipart + files).

### Double-submit prevention

Legacy form submissions were protected server-side via `phpgw::is_repost()`, which checks a `click_history` token stored in the session. REST write paths bypass this mechanism unless the token is explicitly forwarded.

**How `click_history` works**

- `Sessions::generate_click_history()` computes `md5(login + time)` once per page load (lazy init). All links on the same page share the same token.
- `phpgw::link()` appends `click_history=<hash>` to every server-rendered URL.
- `phpgw::is_repost()` marks the token as consumed on first use; a second request with the same token returns `true`.

**Client-side rule: `isSubmitting` guard**

Introduce a boolean `isSubmitting` in the form submit handler:

```js
var isSubmitting = false;

form.on('submit', function(e) {
    if (isSubmitting) { e.preventDefault(); return false; }
    isSubmitting = true;
    setSubmitButtonsDisabled(true);
    // ... fetch ...
    .then(function(data) {
        // on error:
        isSubmitting = false; setSubmitButtonsDisabled(false);
        // on apply success (stay on page):
        isSubmitting = false; setSubmitButtonsDisabled(false);
        // on save/create success (redirect): isSubmitting stays true until page reloads
    })
    .catch(function() {
        isSubmitting = false; setSubmitButtonsDisabled(false);
    });
});
```

The guard is in JS memory and resets automatically on a full page reload — so a user who reloads and saves again is never blocked.

**`click_history` forwarding rules by action**

| Submit button | Forward `click_history`? | Rationale |
|---|---|---|
| `values[save]` | **Yes** — extract from `form.action` or `strBaseURL` | Redirects after success; token is consumed exactly once. |
| `values[create]` (new record) | **Yes** | Same as save — redirects on success. |
| `values[apply]` | **No** | Stays on page; same-page token would be consumed on first apply and rejected as repost on all subsequent applies. `isSubmitting` guard is sufficient. |

**Implementation in `buildEntityRestRequest(form, submitterName)`**

Pass the submitter button name to `buildEntityRestRequest`. Skip `click_history` when `submitterName === 'values[apply]'`:

```js
function buildEntityRestRequest(form, submitterName) {
    var isApply = (submitterName === 'values[apply]');
    var clickHistory = isApply ? '' : (query.click_history || '');
    // ... fall back to strBaseURL only when !isApply ...
}
```

**Do not** add `click_history` to apply REST calls. The `isSubmitting` guard is the sole double-submit defence for that path.

### Checklist

- Intercept submit actions in JS only for intended buttons.
- Build payload from `FormData` with nested object support.
- Route to `POST` for create and `PUT` for update.
- Add `isSubmitting` guard and disable submit buttons during in-flight requests.
- Reset `isSubmitting` (and re-enable buttons) in the error path and in the apply-success path.
- Forward `click_history` query parameter for save/create REST calls only.
- Maintain non-JS fallback for safety.

## Concrete Implementation Checklist (Entity vs Location)

Use this as the execution checklist for closing the parity gap identified in the latest analysis.

### Step 1: Location client REST submit bridge

- [x] Add submit interception in location client JS for the main edit form.
- [x] Keep non-JS fallback by only intercepting when request-building succeeds.
- [x] Build REST write target dynamically:
    - create: `POST /property/location/add`
    - update: `PUT /property/location/{location_id}`
- [x] Add click_history forwarding for save submissions.
- [x] Add in-flight guard (`isSubmitting`) and disable/enable submit buttons.
- [x] Show inline REST success/error alerts.
- [x] Preserve legacy redirect semantics by redirecting to legacy edit URL after successful save.

### Step 2: Location REST ACL parity

- [x] Add explicit ACL checks in `LocationController` write endpoints (`add`, `save`, `delete`).
- [x] Ensure ACL scope matches legacy `property_uilocation` behavior.
- [x] Add/extend tests for ACL deny paths (403).

### Step 3: Location REST payload contract hardening

- [ ] Add controller-level request normalization/shape checks (location equivalent of entity `normalizedSavePayload`).
- [ ] Return consistent 400 payloads for malformed request bodies and validation errors.
- [ ] Document payload schema in OpenAPI annotations.

### Step 4: HTTP semantics parity

- [ ] Return `201 Created` for successful location create.
- [ ] Keep update as `200 OK` and invalid identifier as `400`.

### Step 5: Test coverage parity

- [ ] Expand `LocationControllerTest` with malformed payload and contract cases.
- [ ] Add client contract smoke checks for save interception and redirect behavior.
- [ ] Keep helper regression tests for transport-key stripping and dynamic mapping.

## Phase F: Decommission Legacy Endpoints

Remove legacy handlers only when all gates pass.

### Retirement gates

1. No PHP/XSL/JS references.
2. No observed access-log usage.
3. Parity tests and manual smoke tests green.
4. Deprecation note communicated with removal milestone.

## Payload Modernization Rules

When replacing session/POST-dependent legacy enrichment with explicit payloads:

1. Introduce explicit top-level payload elements for context metadata.
2. Keep domain values in `values` / `values_attribute` unless a stronger schema is established.
3. Preserve resulting persisted dataset, not internal implementation details.

### Example: Relation Metadata Pattern

The `uientity` migration introduced `RelationInfo` to carry context that was previously implicit in legacy form/session behavior.

Fields used:

- `location_code`
- `p_num`
- `p_entity_id`
- `p_cat_id`
- `tenant_id`
- `origin`
- `origin_id`

### Dynamic location depth

Do not assume fixed location depth (`loc1..loc4`).
Build `location_code` from all present `locN` fields sorted by numeric level.

## OpenAPI/Swagger Requirements

Every migrated write endpoint must document:

1. The new payload main element(s).
2. JSON and multipart variants when both are supported.
3. Concrete examples mirroring real integration scenarios.

Minimum schema set:

- `...SaveRequest`
- context schema (for example `RelationInfo`)
- success receipt schema

Regenerate spec after annotation updates and verify generated JSON contains the new schemas and examples.

## Testing Strategy

## Automated

- Unit tests for shared helper/service workflows.
- Controller tests for REST orchestration and error paths.
- Lint checks for modified PHP/JS files.

## Parity tests

Validate parity between legacy and REST for:

- Validation outcomes
- Transaction rollback behavior
- Checklist persistence
- File handling and error propagation
- ACL outcomes

## Manual smoke tests

At minimum test:

1. List page load + DataTable fetch.
2. Edit page sub-tab loads (files, related, inventory, etc.).
3. Create flow with apply/save variants.
4. Update flow with and without file upload.
5. Delete flow refresh behavior.
6. Origin/ticket-linked and parent-linked records.

## Anti-Patterns to Avoid

- Big-bang rewrites of UI + API + data layer at once.
- Duplicated save business logic in multiple layers.
- Hardcoded JS endpoint strings with hidden assumptions.
- Removing legacy endpoints before reference and usage checks.
- Mixing presentation HTML into REST contracts unless explicitly needed.

## Module Migration Checklist Template

Use this checklist per module (`uitts`, `uiproject`, `uiworkorder`, `uirequest`):

- [ ] Legacy contracts documented (`index`, `edit/view`, `save`, sub-resources)
- [ ] REST endpoints added with ACL parity
- [ ] OpenAPI annotations + examples added
- [ ] Shared helper/service extracted for save orchestration
- [ ] Read-path JS switched to REST
- [ ] Write-path JS switched to REST (`isSubmitting` guard added)
- [ ] `click_history` forwarded for save/create REST calls; skipped for apply
- [ ] `isSubmitting` reset after apply success and on error (buttons re-enabled)
- [ ] Dynamic SQL in migrated endpoints uses prepared statements
- [ ] Paginated DB reads use `Db::limit_query_with_params()` where practical
- [ ] Fallback behavior retained where required
- [ ] Automated tests updated and passing
- [ ] Manual smoke tests completed
- [ ] Legacy endpoints marked deprecated
- [ ] Legacy endpoints removed after usage gate

## Module-Specific Companion Document Template

Create one companion document per module migration.

Recommended file name:

- `doc/{module}_rest_client_migration.md`

Recommended sections:

1. Scope boundaries
2. Legacy surfaces and contracts to preserve
3. REST endpoints and route map
4. Client cutover plan (read first, write second)
5. ACL parity rules
6. DB migration notes (prepared statements and paging)
7. Feature-specific non-generic caveats
8. Contract freeze matrix link
9. Go-live and rollback checkpoints

Each companion document should only contain non-generic details that are not broadly reusable.

## Current Module Companions

- `doc/location_rest_client_migration.md`
- `doc/request_rest_client_migration.md`
- `doc/uilocation_phase_a_contract_matrix.md`

## Suggested Rollout Order for Remaining Modules

1. `uirequest` (smaller surface than `uitts` and `uiworkorder`)
2. `uiproject`
3. `uiworkorder`
4. `uitts` (typically widest and most integration-heavy)

## Definition of Done

A module is considered migrated when:

1. Client read/write flows use REST endpoints for primary user paths.
2. Legacy and REST outcomes are parity-verified.
3. Swagger/OpenAPI reflects real payload contracts and examples.
4. Legacy adapter paths are either retired or explicitly temporary with a removal milestone.
