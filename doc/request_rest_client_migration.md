# Request REST Client Migration (Module-Specific Scaffold)

This document is a module-specific companion for Request migration.

Use together with:

- `doc/rest_client_migration_playbook.md` (generic baseline)

Status:

- Owner: TODO
- Branch: TODO
- Last updated: TODO
- Migration phase: TODO

## 1. Scope Boundaries

Module focus:

- `src/modules/property/inc/class.uirequest.inc.php`
- `src/modules/property/inc/class.borequest.inc.php` (if applicable)
- `src/modules/property/inc/class.sorequest.inc.php` (if applicable)

Out of scope for this migration wave:

- TODO

## 2. Legacy Surfaces and Contracts to Preserve

List all request-specific read/write surfaces and freeze their contracts before replacing transport.

Read/data surfaces:

- TODO (example: list query, summary query, helper JSON endpoints)

Write/side-effect surfaces:

- TODO (example: save, delete, inline edit, assignment updates)

Navigation-only legacy links that remain temporarily:

- TODO

## 3. REST Endpoints and Route Map

Document route mapping from legacy calls to `/property/request` REST endpoints.

| Legacy surface | Target REST route | Method | Notes |
| --- | --- | --- | --- |
| TODO | TODO | GET | TODO |
| TODO | TODO | POST | TODO |

## 4. Client Cutover Plan (Read First, Write Second)

Read cutover:

1. TODO
2. TODO
3. TODO

Write cutover:

1. TODO
2. TODO
3. TODO

Fallback rules during transition:

- Keep legacy fallback only for unmigrated surfaces.
- Remove fallback immediately after parity checks pass.

## 5. ACL Parity Rules

For each migrated endpoint, verify parity for:

- read/add/edit/delete/manage visibility
- field-level edit behavior
- assignment/approval actions
- attachments/history visibility

Record ACL parity evidence in PR notes.

## 6. DB Migration Notes (Prepared Statements and Paging)

Track Request storage-layer migrations here.

- Replace interpolated dynamic values with prepared statements where practical.
- Preserve ordering, paging, totals, and export behavior.
- Keep any dynamic structural SQL values constrained to trusted internal enums.

SQL worklist:

- [ ] Identify interpolated query fragments in `sorequest`
- [ ] Migrate high-risk read paths first
- [ ] Migrate write paths
- [ ] Verify `total_records`/paging parity

## 7. Feature-Specific Non-Generic Caveats

Capture request-only caveats not suitable for the generic playbook.

- TODO
- TODO

## 8. Contract Freeze Matrix Link

Create and link a request contract matrix before endpoint cutover.

- Suggested file: `doc/uirequest_phase_a_contract_matrix.md`
- Status: TODO

## 9. Go-Live and Rollback Checkpoints

Go-live minimums:

1. Request data flows use REST endpoints for migrated surfaces.
2. Contract matrix rows are frozen and verified.
3. Remaining legacy usage is navigation-only and documented.
4. SQL parity checks pass for filters/sorting/paging/totals.

Rollback plan:

- Keep a short-lived feature flag or fallback toggle until first production verification window closes.
- Document exact rollback command/path: TODO
