## Plan: Project/Workorder Client-API Split

Migrate Project first, then Workorder, while keeping legacy menuaction shells for navigation/rendering. Use the successful Location/Entity strangler pattern: route new reads/writes/files to REST controllers, keep legacy UI methods as thin shells/deprecated bridges, and progressively disable legacy data dispatch.

**Steps**
1. Phase 0: Migration Guardrails and Contract Baseline
- Freeze baseline contracts for Project/Workorder data payloads (DataTables and canonical envelope variants) and document accepted payload keys and response shapes.
- Add/refresh migration sections in doc/rest_client_migration_playbook.md for Project and Workorder with route map, deprecation policy, and fallback rules.
- Dependency: none.

2. Phase 1: Project REST Foundation (Read Path)
- Create src/modules/property/controllers/ProjectController.php with index/list/show endpoints modeled after EntityController/LocationController response helpers.
- Add Project route group in src/modules/property/routes/Routes.php with collection, item, and read-side helper endpoints.
- Implement DataTables-compatible and canonical list variants for project list read path (same dual strategy as Location/Entity).
- Keep src/modules/property/inc/class.uiproject.inc.php index/query/view as shell only; ensure data source URLs can point to REST endpoints without replacing shell rendering.
- Depends on Phase 0.

3. Phase 2: Project REST Mutations and Files
- Introduce src/modules/property/helpers/ProjectFormHelper.php to centralize payload normalization/validation/persist logic (pattern from LocationFormHelper/EntityFormHelper).
- Implement store/update/destroy in ProjectController with ACL checks, validation error envelope consistency, and transaction-safe behavior.
- Migrate project file APIs (list/upload/delete/tag metadata) into ProjectController endpoints while preserving existing popup/shell interactions.
- Replace legacy HTML-in-data generation with pure data responses; keep presentation formatting in client/XSL formatters.
- Depends on Phase 1.

4. Phase 3: Project Legacy Boundary Hardening
- In src/modules/property/inc/class.uiproject.inc.php, mark data/mutation public_functions false once REST endpoints are wired and consumers switched.
- Retain only shell/navigation handlers as true.
- Add deprecation docblocks with exact REST replacement routes and payload contract notes.
- Depends on Phase 2.

5. Phase 4: Workorder REST Foundation (Read Path)
- Create src/modules/property/controllers/WorkorderController.php and Workorder routes in src/modules/property/routes/Routes.php.
- Implement index/list/show, using explicit request parsing (no implicit Sanitizer globals in controller contracts).
- Extract read orchestration from src/modules/property/inc/class.boworkorder.inc.php into explicit service-style methods where needed to accept request params directly.
- Keep src/modules/property/inc/class.uiworkorder.inc.php index/query/view/edit shells active.
- Depends on Phase 3 (sequencing decision: Project first).

6. Phase 5: Workorder Mutations, Approval/Status, and Files
- Add src/modules/property/helpers/WorkorderFormHelper.php to normalize/validate payloads and coordinate save/edit workflows.
- Implement store/update/destroy/status/receive-order style mutation endpoints in WorkorderController with consistent response envelopes.
- Migrate file endpoints (list/upload/delete/update metadata) from class.uiworkorder.inc.php to REST; keep UI shell popups intact.
- Ensure interlink origin/target data is returned as pure data (no embedded HTML anchors) and formatting remains in client layer.
- Depends on Phase 4.

7. Phase 6: Workorder Legacy Boundary Hardening
- Flip non-shell Workorder public_functions in src/modules/property/inc/class.uiworkorder.inc.php to false after route adoption.
- Keep shell/navigation endpoints true; deprecate legacy method docs with REST route replacements.
- Depends on Phase 5.

8. Phase 7: Cross-Module Integration and Regression Hardening
- Validate Project <-> Workorder relations, entity target rows, voucher/order side effects, budget recalculation paths, and interlink behavior under new controllers.
- Add/expand controller tests (ProjectControllerTest, WorkorderControllerTest) and update existing tests that assert target/document/file payloads.
- Run static grep audits to ensure legacy data endpoints are disabled where intended and documentation matches real routes.
- Depends on Phases 2 and 5, can run partially in parallel with 3/6 once interfaces stabilize.

**Parallelism and Dependencies**
1. Sequential blocks
- Phase 1 -> 2 -> 3 must complete before Workorder migration begins (chosen rollout strategy).
- Phase 4 -> 5 -> 6 follows the same pattern.

2. Parallel opportunities
- Docs/tests can be drafted in parallel with each implementation phase, but merge only after endpoint contracts are finalized.
- Client-side formatter updates and XSL/JS endpoint rewires can run in parallel once controller route shapes are stable per phase.

**Relevant files**
- /var/www/html/src/modules/property/controllers/EntityController.php — reference for REST controller style, OpenAPI schemas, helper patterns.
- /var/www/html/src/modules/property/controllers/LocationController.php — reference for DataTables + canonical dual list strategy and ACL/error envelopes.
- /var/www/html/src/modules/property/routes/Routes.php — add Project and Workorder route groups.
- /var/www/html/src/modules/property/inc/class.uiproject.inc.php — legacy project shell/data handler split and public_functions hardening.
- /var/www/html/src/modules/property/inc/class.boproject.inc.php — project orchestration; candidate extraction points for explicit request parameter handling.
- /var/www/html/src/modules/property/inc/class.soproject.inc.php — project SQL/data operations; prioritize parameter safety and stable return shapes.
- /var/www/html/src/modules/property/inc/class.uiworkorder.inc.php — legacy workorder shell/data split and public_functions hardening.
- /var/www/html/src/modules/property/inc/class.boworkorder.inc.php — workorder orchestration and status/approval behavior.
- /var/www/html/src/modules/property/inc/class.soworkorder.inc.php — workorder SQL/data operations and linked behavior.
- /var/www/html/src/modules/property/js/base/entity.edit.js — reference for API URL migration style in client JS.
- /var/www/html/src/modules/property/js/base/location.edit.js — reference for form submit interception and REST wiring patterns.
- /var/www/html/src/modules/phpgwapi/templates/bootstrap/datatable2.xsl — shared DataTables/edit feedback handling patterns to reuse.
- /var/www/html/doc/rest_client_migration_playbook.md — update migration playbook with project/workorder specifics.
- /var/www/html/tests/controllers/EntityControllerTest.php — reference test style for controller contract assertions.

**Verification**
1. Static and syntax checks
- php -l for all touched PHP files after each phase.
- grep audit for public_functions flags and deprecated method docs in class.uiproject.inc.php and class.uiworkorder.inc.php.

2. Contract checks
- Validate DataTables response keys: draw, recordsTotal, recordsFiltered, data.
- Validate canonical response envelope where implemented: status, data, meta.
- Verify origin/interlink payloads are pure data and no HTML is returned by REST data endpoints.

3. Route and behavior checks
- Exercise CRUD + file endpoints for Project and Workorder.
- Validate backward compatibility of shell menuaction navigation (UI still loads and routes data via REST).
- Verify entity target/workorder relation endpoints keep behavior parity.

4. Regression tests
- Extend controller tests for new Project/Workorder controllers.
- Re-run existing controller tests impacted by relation/file/status behavior.

**Decisions**
- Rollout order: Project first, then Workorder.
- Legacy strategy: keep menuaction shells, migrate data and mutation paths to REST first.
- Out of scope for this migration plan: full XSL shell replacement and complete frontend rewrite.

**Further Considerations**
1. Introduce shared helper abstractions
- Consider a common base/helper for DataTables parsing + envelope responses to reduce controller drift.

2. SQL hardening priority
- Prioritize parameterized query hardening in high-risk mutable paths of soproject/soworkorder before enabling broad REST writes.

3. Cutover checkpoints
- Use per-module cutover checklists (routes live, shell rewired, tests green, legacy data dispatch disabled) before flipping public_functions flags.