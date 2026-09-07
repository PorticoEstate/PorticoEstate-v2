# Todo Module Client/REST Conversion Guide

## 1. Purpose and Scope

This document explains how the `todo` module works today, how it was converted from legacy page-centric behavior to a client + REST API architecture, and how to reuse the same approach as a prototype for converting other modules.

Primary goals:

- Preserve existing business behavior and access rules.
- Move data operations to JSON endpoints.
- Keep navigation and page routing stable for end users.
- Render UI on the client with explicit, documented payload contracts.
- Add security hardening (input normalization, CSRF protection for mutating calls).

This guide is written as a practical migration playbook for future agent sessions.

---

## 2. Module Structure

Top-level `todo` module layout:

- `routes/Routes.php`: route registration and route middleware.
- `controllers/TodoController.php`: REST API endpoints and payload mapping.
- `viewcontrollers/TodoViewController.php`: page shell endpoints (Twig + legacy wrapper).
- `inc/class.botodo.inc.php`: business layer wrapper over storage layer.
- `inc/class.sotodo.inc.php`: storage/database layer.
- `html/base/**`: generic Twig templates and client-side JS/CSS selected through `@views`.
- `setup/phpgw_*.lang`: localization keys.

Important split:

- View controllers return HTML shells/pages.
- API controller returns JSON payloads.
- Client JS calls API and renders dynamic content.

---

## 3. Route Model (Legacy Navigation + REST Data)

The module intentionally keeps classic view navigation while separating data operations into REST endpoints.
Legacy `menuaction=todo.uitodo.*` redirect compatibility was removed after the
list page moved fully to `/todo/view/todos` and `todo_datatable.*`.

### 3.1 View routes

Prefix: `/todo/view`

Examples:

- `/todo/view/todos`
- `/todo/view/todos/add`
- `/todo/view/todos/{id}`
- `/todo/view/todos/{id}/edit`
- `/todo/view/todos/{id}/delete`
- `/todo/view/todos/matrix`

These return HTML pages (Twig components wrapped in legacy layout rendering).

### 3.2 API routes

Prefix: `/todo`

Examples:

- `GET /todo/todos`
- `GET /todo/todos/{id}`
- `POST /todo/todos`
- `PUT /todo/todos/{id}`
- `PATCH /todo/todos/{id}/status`
- `DELETE /todo/todos/{id}`
- `GET /todo/todos/export/csv`
- `GET /todo/categories`

These return JSON (except CSV export).

### 3.3 Route middleware

Module route group uses:

- session middleware
- access verification middleware
- CSRF middleware for mutating endpoints and related view pages

CSRF behavior details are in Section 8.

---

## 4. Layered Architecture

## 4.1 Storage layer (`class.sotodo.inc.php`)

Responsibilities:

- SQL read/write operations.
- ACL-aware filtering in listing queries.
- Recursive tree/sub-task operations.
- History writes for changes.

Current behavior notes:

- Writes (`add_todo`, `edit_todo`) use prepared statements.
- List query has legacy dynamic SQL construction but was hardened with integer enforcement for key list fragments.
- Group-preserving ordering for hierarchy sorting was implemented.

## 4.2 Business layer (`class.botodo.inc.php`)

Responsibilities:

- Session-bound list behavior and defaults.
- Date formatting to user preference for list output.
- Assigned/user group transformations.
- Permission helper (`check_perms`).
- Validation (`check_values`) and save orchestration.

## 4.3 API controller (`TodoController.php`)

Responsibilities:

- Parse and normalize incoming API payloads.
- Call business layer operations.
- Map legacy structures to stable JSON contract.
- Return consistent API responses and errors.
- OpenAPI annotations.

Important mapping helpers:

- `mapTodoItems`
- `mapTodoDetail`
- `mapAssignedEntries`
- `getTodoHistoryData`

## 4.4 View controller (`TodoViewController.php`)

Responsibilities:

- Return Twig page shells.
- Provide page bootstrapping data (URLs, language strings, defaults).
- Build matrix rendering input and wrapper.
- Provide CSRF token metadata to Twig pages.

## 4.5 Client layer (`html/todo/**`)

Responsibilities:

- Fetch data from REST API.
- Render dynamic UI content.
- Submit JSON for create/update/delete/status operations.
- Include CSRF tokens in mutating requests.

---

## 5. Data Contract Strategy

Key migration principle:

- Avoid returning server-rendered HTML blobs for dynamic tables/details when possible.
- Return structured arrays/objects and render in client JS.

### 5.1 Example: Assigned entities

Old style:

- String field with line breaks or HTML-style formatting.

New style:

- `assigned_entries: [{ id, type, name }]`

Benefits:

- Stronger client control over rendering.
- Easier localization and formatting changes.
- Cleaner API schema and tests.

### 5.2 Example: History

Old style:

- Server-rendered history HTML table.

New style:

- `history` array with normalized fields (`status_label`, `new_value`, `old_value`, `datetime`).
- Client renders table.

Datetime behavior:

- Unix timestamps are formatted server-side according to user date format plus `H:i`.

---

## 6. Frontend Rendering Patterns

## 6.1 Main list (`todo_datatable.twig`)

Patterns:

- shared `@phpgwapi_components/app_datatable/app_datatable.twig` component.
- local initializer in `html/base/index/todo_datatable.js` using `AppDatatable.init()`.
- formatter function per column (`renderTitle`, `renderAssigned`).
- ID rendered as direct view link.
- `assigned_entries` rendered as escaped multiline text.
- filter values are stored in session-backed DataTables state and shown as removable active filter chips.
- page length and length menu are derived from the user's `common.maxmatchs` preference.
- inputPaging is enabled through the shared component.
- row actions use `rowActionsDisplay: 'contextMenu'`, so `view`, `edit`, `add sub project`, and `delete`
  are not rendered as extra columns.
- `rowActionsToolbar: true` mirrors those row actions into the DataTables button row. The buttons are
  disabled until a row is selected. Clicking a row toggles selection on and off; right-clicking a row
  selects it and opens the context menu.

## 6.2 View page (`todo_view.js`)

Patterns:

- Fetch detail + history by ID.
- Tabbed details/history rendering.
- History table generated from structured API array.
- Assigned entities rendered from `assigned_entries` only.

## 6.3 Add/edit pages (`todo_add.js`, `todo_edit.js`)

Patterns:

- Build JSON payload from form.
- Date parsing from user format.
- Multi-select arrays for assigned users/groups.
- CSRF headers and payload token fields sent on submit.

## 6.4 Delete page (`todo_delete.js`)

Patterns:

- Fetch detail for confirmation.
- Call DELETE API with optional `subs=1`.
- Map backend reason codes to user-friendly messages.
- Send CSRF headers.

## 6.5 Matrix page (`todo_matrix.js`)

Patterns:

- Inline status edit via PATCH.
- Integer-only status validation (0..100).
- Auto-submit month/year filters on change.
- Hidden CSRF fields injected into matrix filter form.

---

## 7. OpenAPI / Swagger Approach

`TodoController.php` includes `@OA` annotations for:

- tag
- reusable schemas (`TodoItem`, `TodoDetail`, `TodoCategory`, `TodoUpsertRequest`, `TodoErrorResponse`)
- endpoint responses including common error response refs

Guidance for other modules:

1. Define schema objects first.
2. Annotate every endpoint with success + common error responses.
3. Keep response contract aligned with actual payloads (do not document removed fields).

---

## 8. CSRF Design (Slim)

The module uses `slim/csrf` (v1.5.1) and applies it at route level in `routes/Routes.php`.

### 8.1 Why route-level middleware

- Scope is explicit for module conversion.
- Easy to prototype before global rollout.
- Allows special-case handling for known legacy behaviors.

### 8.2 Mutating endpoint protection

CSRF is enforced for:

- `POST /todo/todos`
- `PUT /todo/todos/{id}`
- `PATCH /todo/todos/{id}/status`
- `DELETE /todo/todos/{id}`

### 8.3 Token transport and compatibility

To handle browser/proxy differences and JSON parsing timing:

- Tokens are provided to view pages from request attributes (`csrf_name`, `csrf_value`).
- JS sends tokens in:
  - headers: `csrf_name`, `csrf_value`
  - fallback headers: `X-CSRF-NAME`, `X-CSRF-VALUE`
  - JSON payload fields for mutating requests
- Route middleware pre-parses JSON body for mutating methods before CSRF validation.

### 8.4 DataTables exception

`POST /todo/todos` is also used by DataTables server-side listing.

A bypass is implemented for recognized list payloads (`draw/columns/order`) to avoid breaking read-only list requests.

---

## 9. Input Security and Normalization

## 9.1 Implemented hardening in API controller

`readPayload` now normalizes:

- `title`, `descr`: plain-text sanitization (HTML stripped, trimmed).
- numeric fields: cast to integers.
- `access`: strict boolean conversion.
- `assigned`, `assigned_group`: positive integer ID list CSV normalization.

## 9.2 Storage layer hardening

In list query construction:

- account/group/public user IDs are forced to integer lists before SQL fragment composition.
- dynamic `IN (...)` fragments are built from sanitized int arrays.

## 9.3 Remaining security considerations

- Legacy query-building in list reads still exists (not fully rewritten to prepared dynamic builders).
- CSRF is currently module-scoped; global strategy may be desired later.

---

## 10. Hierarchy and Sorting Behavior

Important module-specific behavior implemented as prototype logic:

- When sorting, children remain grouped under their top parent.
- Ordering is based on top/root parent sort key and stable subtree order.
- Matrix includes all hierarchy levels, not only shallow levels.

This is critical for migrating other tree-structured modules.

---

## 11. Migration Blueprint for Other Modules

Use this staged approach.

### Stage A: Discover and freeze behavior

- Identify existing UI routes and user flows.
- Inventory business rules and ACL checks.
- Document output contract currently expected by UI.

### Stage B: Add REST controller alongside legacy view controller

- Keep old navigation URLs for pages.
- Build JSON endpoints in parallel.
- Map legacy model data to stable contract objects.

### Stage C: Move rendering to client incrementally

- Start with list page read-only render.
- Prefer the shared `AppDatatable` component for list pages that need DataTables features.
- Convert detail page second.
- Convert add/edit/delete operations to JSON.
- Keep old route wrappers until parity is validated.

### Stage D: Security hardening

- Add CSRF middleware for mutating routes.
- Normalize payloads in one place (`readPayload`-style helper).
- Ensure write SQL uses prepared statements.
- Add explicit integer allowlists for dynamic SQL fragments.

### Stage E: API documentation

- Add OpenAPI schemas and endpoint annotations.
- Reuse common error schema.
- Validate docs against real payloads.

### Stage F: Cleanup

- Remove server-rendered fallback fields once client uses structured data.
- Remove dead helper methods after reference scan.
- Remove legacy redirect shims after all in-repo links point to `/todo/view/*` routes.
- Keep CSV/export adapters where plain-text flattening is needed.

---

## 12. Recommended File Pattern for Future Conversions

Per module, mirror this structure:

- `routes/Routes.php`
- `controllers/<Module>Controller.php` (REST)
- `viewcontrollers/<Module>ViewController.php` (page shell)
- `html/base/{index,view,add,edit,delete}/...`
- `inc/class.bo<module>.inc.php`
- `inc/class.so<module>.inc.php`
- `doc/<MODULE>_CLIENT_REST_CONVERSION_GUIDE.md`

---

## 13. Testing and Verification Checklist

Before merge:

- Routing: all existing view URLs still load, and API endpoints are reachable and ACL-protected.
- Behavior parity: list/filter/sort/category behavior matches legacy expectations, parent/child tree
  integrity is preserved, and delete semantics are unchanged.
- Security: CSRF failures return 400 for mutating calls without token, valid token paths work for
  POST/PUT/PATCH/DELETE, and input normalization is applied consistently.
- Contract: frontend code consumes only documented fields, and removed fields are not referenced by
  client code.
- Validation: `php -l` passes for modified PHP files, and modified JS/Twig files have no diagnostics.

---

## 14. Operational Notes and Gotchas

- DataTables can use POST for list reads. If CSRF is strict on all POST blindly, list may break.
- Some environments may drop underscore header names; keep fallback header support if needed.
- If mutating API uses JSON body, CSRF middleware may require explicit parsed-body handling depending on middleware order.
- Keep user date format handling centralized to avoid mismatch between API and UI.

---

## 15. Known Decisions in This Prototype

- Legacy navigation preserved under `/todo/view/*`.
- Data moved to REST under `/todo/*`.
- Structured arrays preferred over pre-rendered HTML in API payloads.
- CSRF implemented route-locally for Todo first.
- Assigned string in API replaced by `assigned_entries` (CSV export still flattens to string for file output).
- `datatable2.twig` was replaced by the shared `AppDatatable` component for the Todo list.
- The old `inc/class.uitodo.inc.php` compatibility shim was deleted.
- The unused old `html/base/index/todo_index.twig`, `todo_index.js`, and `todo_index.css` files were deleted.
- Row actions are presented through a context menu and selected-row toolbar instead of table action columns.

---

## 16. Suggested Next Improvements (Optional)

1. Extract reusable CSRF middleware helper into shared module utilities for reuse.
2. Move more dynamic list SQL fragments to prepared-query builder patterns.
3. Add integration tests for mutating endpoints with and without CSRF token.
4. Add contract tests for JSON payload shape (`TodoItem`, `TodoDetail`, `history`).
5. Standardize a shared payload normalizer trait for all converted modules.
6. Reuse the `AppDatatable` pattern from Todo, Notes, Messenger, and Booking for future list migrations.

---

## 17. Quick Reference: Key Todo Files

- Routing and middleware: `src/modules/todo/routes/Routes.php`
- API controller: `src/modules/todo/controllers/TodoController.php`
- View controller: `src/modules/todo/viewcontrollers/TodoViewController.php`
- Business layer: `src/modules/todo/inc/class.botodo.inc.php`
- Storage layer: `src/modules/todo/inc/class.sotodo.inc.php`
- Main list page: `src/modules/todo/html/base/index/todo_datatable.twig`
- Main list client: `src/modules/todo/html/base/index/todo_datatable.js`
- Detail page client: `src/modules/todo/html/base/view/todo_view.js`
- Add/edit clients: `src/modules/todo/html/base/add/todo_add.js`, `src/modules/todo/html/base/edit/todo_edit.js`
- Delete client: `src/modules/todo/html/base/delete/todo_delete.js`
- Matrix client: `src/modules/todo/html/base/matrix/todo_matrix.js`

---

## 18. Session Handoff Notes Template

Use this template in future agent sessions:

- Scope converted:
  - [module + endpoints + pages]
- Legacy behavior preserved:
  - [list of invariants]
- New REST contract:
  - [schemas and removed fields]
- Security status:
  - [CSRF strategy, input normalization, remaining gaps]
- Validation status:
  - [lint/tests/manual checks]
- Follow-up tasks:
  - [ordered TODO list]

This keeps cross-session continuation deterministic and reduces re-discovery cost.
