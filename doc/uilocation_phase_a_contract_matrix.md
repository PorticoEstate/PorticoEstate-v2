# property_uilocation Phase A Contract Freeze Matrix

Purpose: lock current request/response behavior before REST cutover for `property_uilocation`.

Scope: `src/modules/property/inc/class.uilocation.inc.php`, `src/modules/property/inc/class.bolocation.inc.php`, `src/modules/property/inc/class.solocation.inc.php`.

## How To Use

1. Capture one or more real payload samples per contract row below.
2. Add golden fixtures for request and response payloads.
3. Run parity checks against legacy and REST implementations.
4. Mark each row as `Frozen` only after payload and behavior are validated.

## Contract Matrix

| ID | Surface | Legacy entrypoint | Request contract to freeze | Response contract to freeze | Parity checks | Status |
|---|---|---|---|---|---|---|
| LOC-001 | List DataTable | `query()` via `menuaction=property.uilocation.index&phpgw_return_as=json` | `start`, `length`, `search[value]`, `order`, `columns`, `lookup_tenant`, `export`, `column_search` | JSON with `results`, `total_records`, `draw`; row field names from `uicols` | sorting, paging, `column_search`, default district behavior, `allrows` export behavior | TODO |
| LOC-002 | Role list DataTable | `query_role()` via `menuaction=property.uilocation.responsiblility_role&phpgw_return_as=json` | `user_id`, `role_id`, `type_id`, `search`, `order`, `columns`, `start`, `length` | JSON with `results`, `total_records`, `draw`; includes role-specific dynamic columns | list size parity, role filter parity, search parity, hidden column parity | TODO |
| LOC-003 | Summary DataTable | `query_summary()` via summary view and JSON mode | summary filters (`district_id`, `part_of_town_id`, `filter`), `draw`, export flag | JSON with `results`, `total_records`, `draw` | group/aggregate parity, summary totals parity | TODO |
| LOC-004 | Main download | `download()` default branch | `type_id`, `lookup`, `lookup_tenant`, export flags | exported file columns/labels/order and row count | byte-level headers where feasible, column order, locale/date format consistency | TODO |
| LOC-005 | Summary download | `download()` with `download_type=summary` | summary filters + export flags | exported summary file shape | row count and column parity vs summary table | TODO |
| LOC-006 | Responsibility-role download | `download()` with `download_type=responsiblility_role` | `user_id`, `role_id`, `type_id`, `search` | exported file includes `role_id`, `responsible_contact`, `contact_id` additions | role-specific columns present and populated identically | TODO |
| LOC-007 | Part-of-town options | `get_part_of_town()` | `district_id` | list of options with leading `no part of town` entry | option ordering and first sentinel item parity | TODO |
| LOC-008 | Accounts options | `get_accounts()` | `account_type` in (``, `accounts`, `groups`) | options list, including `Select` and `mine roles` for `accounts` | exact option inclusion rules and ordering parity | TODO |
| LOC-009 | History data | `get_history_data()` | `location_code`, DataTable `draw` | JSON with date-formatted `entry_date` and total count | date format parity and total count parity | TODO |
| LOC-010 | Documents data | `get_documents()` | `location_code`, `doc_type`, DataTable paging/search/order args | merged result set from document + generic document with `total_records` sum | merged source parity, link formatting parity, pagination parity | TODO |
| LOC-011 | Edit/view bootstrap payload | `edit()` / `view()` | `location_code`, `parent`, `sibling`, `lookup_tenant`, `active_tab` | template payload: tabs, datatable definitions, actions, integration links, attribute groups | tab IDs/order, datatable config keys, action visibility by ACL | TODO |
| LOC-012 | Save input mapping and validation | `_populate()` + `save()` | `loc1..locN`, `cat_id`, `values_attribute`, config-driven extra fields | receipt errors/messages, derived `location_code`, `location_parent`, `error_id` behavior | validation message parity and field requirements parity | TODO |
| LOC-013 | Delete flow | `delete()` | `location_code`, ACL context | redirect/receipt behavior and table refresh expectations | ACL denial parity, success/failure UX parity | TODO |
| LOC-014 | Inline edit field | `edit_field()` | editor payload shape from DataTable | JSON outcome and persisted field update behavior | update success/error parity and ACL parity | TODO |
| LOC-015 | Responsibility-role save | `responsiblility_role_save()` | `assign`, `assign_orig`, `user_id`, `role_id` | `message[]`/`error[]` receipt contract | assignment delta handling and message parity | TODO |
| LOC-016 | Location data helper | `get_location_data()` | `location_code` | helper payload consumed by UI | payload key/value parity | TODO |
| LOC-017 | Delivery address helper | `get_delivery_address()` | location selectors/codes | delivery address payload contract | returned address fields parity | TODO |
| LOC-018 | Location exception helper | `get_location_exception()` | `location_code` | exceptions payload contract | alert/vendor exception parity | TODO |
| LOC-019 | Controls/cases/checklists helpers | `get_controls_at_component()`, `get_cases()`, `get_checklists()`, `get_cases_for_checklist()`, `get_assigned_history()` | location and item identifiers, optional year/checklist ids | table JSON payloads and totals | table rows/totals parity across tabs | TODO |
| LOC-020 | Dashboard aggregate | `dashboard()` | `location_code` | three table datasets (projects/tickets/documents), action links | counts, status mapping, and links parity | TODO |

## Required Golden Fixtures

- One fixture for each contract ID above with:
  - request parameters exactly as received from browser.
  - full response payload (or exported file metadata and sample rows).
- Additional fixtures for edge inputs:
  - missing `district_id` with default district preference enabled.
  - deep location depth (at least 5 levels).
  - empty result set for each DataTable endpoint.
  - role save with empty `role_id` and mixed `assign`/`assign_orig` sets.

## Execution Gates

A contract row may be marked `Frozen` only when:

1. Fixture exists and is reviewed.
2. Legacy behavior is replayed from fixture and verified.
3. REST implementation reproduces payload semantics.
4. Any intentional delta is documented in migration notes.

## Notes For REST Cutover Planning

- Keep legacy `menuaction` action links for page navigation and popup UX in early phases.
- Migrate JSON data sources first (read paths), then write/side-effect paths.
- Treat dashboard as a separate phase because it depends on multiple modules.
