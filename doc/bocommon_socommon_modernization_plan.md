# Modernization Plan: property_bocommon and property_socommon

This plan defines a safe, staged conversion of `property_bocommon` and `property_socommon` toward modern helper/service architecture while preserving legacy behavior.

Status owner: Property modernization track
Last updated: 2026-05-08

## Objective

Modernize internals into dedicated services/helpers and keep compatibility for existing `CreateObject('property.bocommon')` and `CreateObject('property.socommon')` callers during transition.

## Constraints

- No big-bang rewrite.
- Keep existing external behavior stable.
- Avoid singleton/shared instance lifecycle for stateful `property_bocommon` behavior.
- Introduce migration in small, verifiable batches.
- During migration, do not call new helper/service classes directly from the rest of the system; use only `property_bocommon` and `property_socommon` as transition adapters.

## Success Criteria

1. Existing callers continue working without contract changes.
2. Extracted service methods pass parity checks against legacy behavior.
3. High-risk flows (save/transaction/ACL) remain behavior-equivalent.
4. Legacy adapters become thin wrappers over modern services.

## Phase Plan

## Phase 1: Inventory and Risk Mapping

- Enumerate all callers of:
  - `CreateObject('property.bocommon')`
  - `CreateObject('property.socommon')`
- Tag call sites by risk:
  - High: save orchestration, transaction boundaries, ACL-sensitive behavior.
  - Medium: mapping/format helpers used in multiple screens.
  - Low: pure lookup/read utilities.
- Record lifecycle assumptions (new instance per call, mutable state expectations).

Deliverable:
- Caller inventory with risk labels.

## Phase 2: Responsibility Split

- Define service boundaries:
  - `CommonDataService` (socommon-like responsibilities).
  - `CommonBusinessService` (bocommon-like reusable business logic).
- Keep stateful request-specific fields in adapter classes initially.

Deliverable:
- Responsibility matrix: method -> owner service/adapter.

## Phase 3: Service Skeletons

- Create service classes under module service namespace.
- Implement first low-risk method set only.
- Keep method signatures stable where practical.

Deliverable:
- New service classes with initial migrated methods.

## Phase 4: Adapter Delegation

- Update `property_socommon` to delegate migrated methods to `CommonDataService`.
- Update `property_bocommon` to delegate migrated methods to `CommonBusinessService`.
- Preserve existing public methods in legacy classes.

Deliverable:
- Legacy classes acting as compatibility adapters.

## Phase 5: Factory Wiring (Safe Lifecycle)

- Add/adjust factory wiring in `property_ofproperty` only after adapter delegation works.
- Lifecycle rules:
  - `property_bocommon`: do not share singleton instance.
  - `property_socommon`: per-request/transient by default.
- InterLink-style direct service instantiation allowed only for stateless operations.

Deliverable:
- Factory wiring aligned with lifecycle safety.

## Phase 6: Incremental Caller Migration

- Migrate low-risk call sites first.
- Validate each batch before continuing.
- Keep fallback path through adapter methods until final cutover.

Deliverable:
- Batched migration log with pass/fail results.

## Phase 7: Validation and Parity

- Add targeted regression tests for extracted methods.
- Run parity checks for:
  - ACL outcomes
  - transaction behavior
  - cache behavior
  - receipt/message shaping
- Perform manual smoke tests on representative UI flows.

Deliverable:
- Test report and parity checklist with sign-off.

## Phase 8: Deprecation and Cutover

- Mark legacy paths deprecated when parity confidence is reached.
- Monitor usage in logs.
- Remove legacy-only implementations after stable window and zero critical regressions.

Deliverable:
- Final cutover report and deprecation/removal record.

## Progress Checklist

- [x] Define migration scope and success criteria
- [~] Inventory legacy entry points and dependencies
- [x] Classify `property_bocommon` stateful behaviors
- [x] Extract `property_socommon` service candidates
- [~] Create modern services and adapters
- [ ] Wire factory cases with safe lifecycle
- [~] Migrate selected call sites incrementally
- [ ] Add regression tests and parity checks
- [ ] Document conventions and rollout guide
- [ ] Cutover, monitor, and deprecate legacy

## Working Notes

- Keep this file updated after each migration batch.
- For each completed item, add date + short summary under a change log section.

## Change Log

- 2026-05-08: Initial plan and checklist created.
- 2026-05-08: Started migration with `App\modules\property\helpers\CommonDataHelper` and delegated selected read-only methods in `property_socommon` (`read_single_tenant`, `select_part_of_town`, `select_district_list`, `get_lookup_entity`, `get_start_entity`, `get_max_location_level`, `get_location_list`, `get_order_type`).
- 2026-05-08: Added initial caller inventory snapshot (`86` bocommon call sites, `14` socommon call sites) in `doc/bocommon_socommon_inventory.md`.
- 2026-05-08: Added `App\modules\property\helpers\CommonBusinessHelper` and delegated low-risk pure methods in `property_bocommon` (`msgbox_data`, `select_list`, `translate_datatype`, `translate_datatype_insert`, `translate_datatype_precision`, `translate_datatype_format`, `add_leading_zero`, `select2String`).
- 2026-05-08: Classified stateful `property_bocommon` method groups and recorded risk notes in `doc/bocommon_socommon_inventory.md`.
- 2026-05-08: Extended adapter migration with additional delegations:
  - `property_socommon` -> `CommonDataHelper`: `fm_cache`, `reset_fm_cache`, `reset_fm_cache_userlist`, `check_location`, `next_id`, `increment_id`.
  - `property_bocommon` -> `CommonBusinessHelper`: `select_multi_list`, `select_multi_list_2`.
- 2026-05-08: Continued internal-only delegation:
  - `property_socommon` -> `CommonDataHelper`: `unquote`, `create_preferences`.
  - `property_bocommon` -> `CommonBusinessHelper`: `get_origin_link`, `utf2ascii`, `ascii2utf`, `make_menu_date`, `make_menu_user`, `choose_select`.
- 2026-05-08: Continued internal-only delegation:
  - `property_socommon` -> `CommonDataHelper`: `new_db`.
  - `property_bocommon` -> `CommonBusinessHelper`: `check_perms`, `check_perms2`, `date_to_timestamp`, `select_datatype`, `select_nullable`.
- 2026-05-08: Continued internal-only delegation:
  - `property_bocommon` thin wrappers now delegated via `CommonBusinessHelper` for socommon-forwarded methods (`create_preferences`, `get_lookup_entity`, `get_start_entity`, `read_single_tenant`, `check_location`, `fm_cache`, `reset_fm_cache`, `reset_fm_cache_userlist`, `next_id`, `increment_id`, `new_db`, `get_max_location_level`, `get_location_list`, `set_pending_action`).
- 2026-05-08: Continued internal-only delegation:
  - `property_bocommon` -> `CommonBusinessHelper`: `read_location_data` wrapper and `select_part_of_town` list-shaping logic.
- 2026-05-08: Continued internal-only delegation:
  - `property_bocommon` -> `CommonBusinessHelper`: `select_part_of_town` retrieval/composition path, `select_district_list`, and `select_category_list`.
- 2026-05-08: Continued internal-only delegation:
  - `property_bocommon` -> `CommonBusinessHelper`: `preserve_attribute_values` and recursive `get_sub_menu` shaping logic.
