# Location REST Client Migration (Module-Specific)

This document contains Location-specific migration guidance that is intentionally not generic.

Use together with:

- `doc/rest_client_migration_playbook.md` (generic baseline)
- `doc/uilocation_phase_a_contract_matrix.md` (contract freeze + parity tracking)

## Implemented Cutovers (Current Branch)

Completed in `sigurd/client_migration`:

1. Location list delete action migrated from legacy `property.uilocation.delete` menuaction URL to `/property/location/delete` compatibility route.
2. Location controller-series write calls in `location.edit.js` migrated from legacy controller helper menuaction URLs to:
  - `/property/location/component/add-control`
  - `/property/location/component/update-control-serie`
3. `LocationController` now exposes compatibility handlers for these write paths while preserving legacy business logic execution.
4. Responsibility-role save action migrated from legacy `property.uilocation.responsiblility_role_save` menuaction URL to `/property/location/responsibility-role/save`.
5. `class.solocation.inc.php` received the first prepared-query hardening slice:
  - parameterized fixed-table `location_code` count queries in `read_entity_to_link()`
  - parameterized `check_location()` lookup by `location_code`
6. `class.solocation.inc.php` add/edit paths hardened further:
  - parameterized `fm_locations` insert values in `add()`
  - parameterized `location_code` updates/selects in `add()` and `edit()`
7. `class.solocation.inc.php` maintenance/helper paths hardened:
  - parameterized `location_code` queries in `update_cat()`
  - parameterized `fm_locations` sync insert/update in `update_location()`
  - parameterized `location_code` and `loc1` filters in delivery/address/exception helper queries
  - parameterized `check_history()`, `get_history()`, and `get_zip_info()` lookups
  - applied `DbRowTrait::dbStrip()` for returned text values in address/district/part-of-town/zip helpers
  - parameterized wildcard filters in `get_children()` and `get_locations_by_name()`
  - applied `DbRowTrait::dbStrip()` for name fields returned by those search helpers
  - parameterized `read_single()` lookup by `location_code`/`id`
  - switched `read_single()` row extraction to fetched-row handling with `DbRowTrait::dbStrip()` for scalar text values
  - hardened `read()` filter inputs (typed ints + escaped wildcard text) for `status`, district/part-of-town filters, owner filter, `location_code`, and column-search text fragments
  - hardened `read()` ordering inputs by normalizing sort direction and rejecting non-tokenized `order` expressions
  - hardened `read()` list filters by normalizing `filter_item` IDs and sanitizing ACL location list fragments before interpolation
  - added defensive `isset(...)` guards for optional `read()` inputs used in role/contact filtering
  - hardened `read()` category and criteria handling by normalizing category arrays and whitelisting `criteria_id` values used for query branching
  - hardened `read()` query-part validation by checking array bounds and casting dot-notation numeric parts to integers
  - hardened `edit_field()` by adding isset() guards and enforcing strict in_array comparison for field-name lookups
  - hardened `add()` by whitelisting allowed column names against database metadata before INSERT interpolation, and adding defensive isset() checks for array keys
  - hardened `read_summary()` by casting filter inputs to integers and removing quoted numeric interpolation in owner filter conditions

- These are transport-level migrations (legacy logic still executes behind REST facade).
- The canonical REST delete route `DELETE /property/location/{location_code}` remains available.

## Scope Boundaries

- Module focus:
  - `src/modules/property/inc/class.uilocation.inc.php`
  - `src/modules/property/inc/class.bolocation.inc.php`
  - `src/modules/property/inc/class.solocation.inc.php`
- Keep legacy page shell and XSL rendering during early phases.
- Keep menuaction navigation links (`view`, `edit`, `add`, `dashboard`, cross-module links) until frontend replacement.
- Migrate JSON/data surfaces first, then write-side effects.

## Location-Specific Risks (Non-Generic)

1. Hierarchical location depth (`loc1..locN`) and `location_code` composition must remain exact.
2. Lookup popup exchange contracts (`lookup`, `lookup_name`, `lookup_fields`, `lookup_fields_entity`) are session-coupled and brittle.
3. Role assignment (`responsiblility_role` and `responsiblility_role_save`) has unique payload and receipt shape.
4. Download has multiple semantic modes (`default`, `summary`, `responsiblility_role`) with custom columns.
5. Dashboard aggregates project/ticket/document data from other modules and should be migrated separately.
6. Listing behavior relies on session/cache metadata in the location storage path.

## Legacy Surface Map

Primary UI adapter methods in `property_uilocation` that need migration tracking:

- Read/list surfaces:
  - `query`, `query_role`, `query_summary`
  - `get_part_of_town`, `get_accounts`, `get_history_data`, `get_documents`
  - `get_location_data`, `get_delivery_address`, `get_location_exception`
  - `get_controls_at_component`, `get_cases`, `get_checklists`, `get_cases_for_checklist`, `get_assigned_history`
- Write/side-effect surfaces:
  - `save` + `_populate`
  - `delete`
  - `edit_field`
  - `responsiblility_role_save`
- Aggregation shell:
  - `dashboard`

## Route and Client Cutover Notes

- Existing REST facade routes are under `/property/location`.
- Current implementation still proxies many calls via legacy UI methods.
- Migration target is endpoint logic that parses request explicitly and avoids mutable global request hydration.

Read cutover order:

1. List (`query`) and summary (`query_summary`)
2. Role list (`query_role`) and role save contracts
3. Helper JSON endpoints used by edit tabs
4. Documents/history helper endpoints

Write cutover order:

1. `edit_field`
2. `delete`
3. `save` (after `_populate` extraction/shared service)
4. `responsiblility_role_save`

## ACL Parity Requirements

For each migrated endpoint, preserve legacy ACL outcomes for:

- read/add/edit/delete/manage visibility and behavior
- role assignment actions
- inline field edits
- document/history/tab visibility

Record ACL parity decision in PR notes for each migrated surface.

## DB Migration Requirements (Location-Specific)

For `class.solocation.inc.php` migration work:

1. Replace interpolated filter values with prepared statements where practical.
2. Prioritize high-risk read paths first (`read`, `read_single`, filter fragments, lookup queries).
3. Keep dynamic table-name interpolation constrained to trusted structural values only.
4. Preserve result ordering, paging, and `total_records` behavior.

## Dashboard Strategy

Treat `dashboard` as deferred unless project/ticket/document upstream contracts are also frozen.

Minimum dashboard parity checks before migrating:

- status mapping parity for tickets
- action link parity (`new project`, `new ticket`)
- table column and count parity for all 3 datasets

## Exit Criteria (Location)

Location migration is complete only when:

1. Primary data flows use `/property/location` endpoints without legacy menuaction data fetches.
2. Contract rows in `doc/uilocation_phase_a_contract_matrix.md` are marked `Frozen` and verified.
3. Remaining legacy links are navigation-only and documented as temporary.
4. Location SQL changes pass prepared-statement review gate and parity checks.
