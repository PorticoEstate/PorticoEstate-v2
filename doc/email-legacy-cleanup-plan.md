# Email Legacy Cleanup Plan

Status: cleanup implemented in the working tree; external consumers cannot be verified from this repository.

This document began as a proposed plan. The execution record below supersedes the original no-deletion status; unchecked tasks remain open and must not be treated as completed.

## Execution Update

- Removed the obsolete email popup entry points, UI/business-object implementations, spellcheck chain, and unreferenced legacy screen templates.
- Reduced the retained menuaction classes to Twig navigation bridges and kept `bosend.send()` for confirmed send compatibility.
- Kept `bopreferences` because shared phpgwapi code still invokes it, and kept generic `html_widgets` folder/form/error helpers used outside the retired screens.
- Kept `error_report.tpl` and both `widget_toolbar.tpl` files because retained `html_widgets` methods still load them.
- Updated attachment rendering to use the REST endpoint. A repository-wide search found no remaining executable references to the deleted class names, popup scripts, or spell services; matches for `class.boaction` are stale comments in shared message code.
- Focused automated checks passed for outgoing send, MIME parsing, compose serialization/prefill, and message content (21 tests, 108 assertions). PHP lint passed for the retained changed email classes, and `git diff --check` passed. A direct generic combobox render check also passed.
- The user-validated address-book, attachment, draft, send, print, and redirect workflows predate this cleanup batch; they have not all been repeated after deletion. Repository searches do not establish whether out-of-repository integrations call retired menuactions.

## Goal and Constraints

Remove the superseded email UI and its orphaned implementation while preserving the Twig/REST workflows and intentional compatibility routes.

This is a standalone cleanup plan based on the current source and user validation, not a claim that every legacy class is unused. Recheck references at execution time, including other modules, tests, direct PHP entry points, hooks, setup registrations, and external/plugin consumers.

| Requirement | Constraint |
| --- | --- |
| REQ-001 | Preserve existing menuaction navigation and bookmarks as thin bridges to Twig. |
| REQ-002 | Keep active data operations on REST and preserve CSRF, account ownership, and authorization checks. |
| REQ-003 | Delete obsolete implementation only after its callers and constructor dependencies are removed. |
| REQ-004 | Preserve confirmed legacy sending until its retirement is separately approved. |
| REQ-005 | Preserve validated behavior and demonstrate that remaining references resolve. |

Do not include unrelated refactoring, database migrations, preference deletion, dependency upgrades, or removal of shared phpgwapi code. Preserve ongoing worktree changes. Do not remove the shared mail transport merely because it lives under `inc/`.

## Validation Already Confirmed

- Twig compose address-book selection uses `/email/api/addressbook`.
- Twig attachments use REST; draft attachment readback was fixed and confirmed.
- Message printing looks correct.
- Legacy send compatibility is confirmed.
- User confirmed regression exercises for address-book selection, upload/delete, drafts, send, print, and redirects.
- User confirmed no callers depend on `uisearch.search()` or `uiindex.mlist()`.

These are user acceptance confirmations. They are not equivalent to exhaustive automated end-to-end coverage or proof that all legacy callers can be deleted together.

## Keep / Simplify / Retire

All paths below are relative to `src/modules/email/` unless otherwise stated.

| Area | Planned treatment | Conditions |
| --- | --- | --- |
| `controllers/`, `services/`, `viewcontrollers/`, `routes/`, `html/base/` | Keep active Twig/REST implementation. | Preserve current request/response and navigation contracts. |
| `inc/class.uiindex.inc.php#index` | Keep thin navigation bridge. | Preserve account, folder, sort, pagination, and click history. |
| `inc/class.uimessage.inc.php#message,printable` | Keep thin navigation bridges. | Preserve message context and `print=1`. |
| `inc/class.uicompose.inc.php#compose` | Keep compose navigation bridge. | Resolve the special `mail_spell_special_handling` branch before deleting its old renderer. |
| `inc/class.uifolder.inc.php#folder` | Keep thin folder navigation bridge. | No legacy folder mutations during navigation. |
| `inc/class.uisearch.inc.php#form` | Keep search navigation bridge. | Remove obsolete search constructor work. |
| `inc/class.uifilters.inc.php#filters_list,filters_edit` | Keep thin navigation bridges. | Remove constructor dependency on `bofilters` before deleting that class. |
| `inc/class.uipreferences.inc.php` | Keep preferences/account navigation bridges. | Remove `bopreferences`/nextmatchs construction and replace any singleton-derived navigation arguments with explicit request parsing. Preserve account selection. |
| `inc/class.uiindex.inc.php#mlist` and `inc/class.uisearch.inc.php#search` | Retire unused handlers and their dispatch entries. | User confirmed unused; remove generated legacy callers in the same phase. |
| `inc/class.uijsaddressbook.inc.php`, `inc/class.bojsaddressbook.inc.php` | Retire email-local frameset picker. | Confirm no external consumers; do not delete the separate shared phpgwapi picker. |
| `inc/class.uiattach_file.inc.php`, `inc/class.boattach_file.inc.php` | Retire email-local attachment popup. | Remove legacy compose/spell links first; preserve REST storage and uploaded files. |
| `inc/class.mail_filters.inc.php` | Conditional whole-file deletion candidate. | Its `make_mlist_box()` generates an obsolete `email.uiindex.mlist` form; remove the caller chain, not just the URL. |
| `inc/class.boindex.inc.php`, `class.bomessage.inc.php`, `class.bofolder.inc.php`, `class.bofilters.inc.php`, `class.bopreferences.inc.php` | Conditional deletion candidates. | Check hooks, constructors, direct callers, and tests; do not infer unused solely from REST service replacements. |
| `inc/class.bosend.inc.php` | Keep compatibility path in this cleanup. | Remove only with separate approval and replacement contracts for send/draft/spell submissions. |
| `inc/class.spell.inc.php`, `class.bocompose.inc.php` | Conditional retention/deletion. | `bosend.spellcheck()` reaches `spell`, which can re-enter legacy compose rendering and popup helpers. Keep the required chain unless deliberately adapted. |
| `inc/class.boaction.inc.php` | Audit per method, not whole-file deletion. | Download, HTML viewing, and mutation links may have compatibility consumers. |
| `templates/base/`, `templates/probusiness/`, other legacy template sets | Delete only orphaned template families. | First remove runtime loaders; XSL wrapper/helper usage must be checked separately. |
| Root PHP scripts such as `compose.php`, `attach_file.php`, `addressbook-js.php`, `addressbook.php`, `view_image.php`, `index.php` | Inspect individually for bridge or retirement treatment. | Do not remove public URLs without a documented redirect or retirement decision. |

## Execution Phases

### Phase 1: Freeze the Deletion Boundary

Plan item P1.1: establish reachability and compatibility evidence before removals (REQ-001 through REQ-005).

- [ ] T001 [Plan:P1.1] Record the current working tree and baseline results for the focused email tests; distinguish existing failures from cleanup regressions.
- [ ] T002 [Plan:P1.1] Inventory menuaction dispatch in `inc/class.ui*.inc.php` and `inc/class.bo*.inc.php`, direct PHP entry points, `routes/Routes.php`, hooks, and setup registrations.
- [ ] T003 [Plan:P1.1] Search the whole repository for candidate class names, factory strings, menuactions, template filenames, and include paths. Classify each hit as active, unreachable renderer, test, comment, or external compatibility concern.
- [ ] T004 [Plan:P1.1] Resolve external/plugin use of popup and old action routes before disabling them. An absence of repository references is not evidence about deployed integrations.

Deliverable: update the table above with concrete deletion decisions and any retained consumers. Do not begin bulk deletion while a candidate's ownership is unresolved.

### Phase 2: Reduce Navigation Classes to Bridges

Plan item P2.1: keep navigation but remove obsolete rendering and constructor work (REQ-001, REQ-003, REQ-005).

- [x] T005 [Plan:P2.1] Simplify `class.uifolder.inc.php`, `class.uifilters.inc.php`, and `class.uimessage.inc.php` to their active redirects; delete unreachable renderer methods/bodies and obsolete properties/imports.
- [x] T006 [Plan:P2.1] Simplify `class.uipreferences.inc.php` navigation without bootstrapping mail or constructing `bopreferences`; preserve default/extra-account routes and request context. (`bopreferences` remains for its shared phpgwapi caller.)
- [x] T007 [Plan:P2.1] Simplify `class.uiindex.inc.php#index`; remove obsolete `index_old_tpl`, `index_xslt_tpl`, and `index_xslt_javascript` renderers after the reference audit. Retire `mlist` and its public dispatch registration.
- [x] T008 [Plan:P2.1] Keep `class.uisearch.inc.php#form` as a bridge; remove the unused `search` handler and dispatch entry, old form/results rendering, and unused constructor initialization.

Verify each bridge still reaches its corresponding `/email/view/*` route with the same account/folder/message context. Do not redirect a legacy POST mutation to a GET endpoint and silently discard its body.

### Phase 3: Retire Old Popup and List Chains

Plan item P3.1: remove superseded UI islands and orphaned business objects (REQ-002, REQ-003, REQ-004).

- [x] T009 [Plan:P3.1] Resolve `class.bosend.inc.php#spellcheck` -> `class.spell.inc.php` -> `class.uicompose.inc.php#compose` before removing the special renderer or `class.bocompose.inc.php`. The obsolete spell/UI chain was removed; `bosend.send()` remains.
- [x] T010 [Plan:P3.1] Remove the email-local address-book UI/backend pair and their direct-script entry points only after all retained callers have an approved replacement. Do not touch phpgwapi's similarly named classes/assets.
- [x] T011 [Plan:P3.1] Remove the email-local attachment popup UI/backend pair and dead launchers once retained legacy compose/spell paths no longer require them.
- [x] T012 [Plan:P3.1] Remove obsolete `mlist` producers in `class.mail_filters.inc.php` and `class.bofilters.inc.php`; delete whole classes only if no live behavior remains. Remove any `class.boindex.inc.php#mlist_data` dependency with the retired consumer.
- [x] T013 [Plan:P3.1] Delete now-unreferenced BO classes individually, after checking constructor, hook, factory, and direct-call dependencies. Keep classes still required by legacy send or shared mail processing.

### Phase 4: Remove Orphaned Assets and Close Old Data Dispatch

Plan item P4.1: remove unused templates/assets and public legacy data operations (REQ-001 through REQ-005).

- [x] T014 [Plan:P4.1] Delete orphaned `.tpl` families for retired compose, address-book, attachment, search, list, filter, and preference renderers across legacy template sets. Keep templates still required by retained compatibility code.
- [ ] T015 [Plan:P4.1] Audit `app_data*.xsl`, legacy JavaScript, images, and template-set registrations against runtime loaders, including `html_widgets` and hooks. Delete only proven orphans; retain translations, help, setup, and shared assets by default.
- [ ] T016 [Plan:P4.1] Repository callers were scanned and `class.boaction.inc.php` was removed, but out-of-repository compatibility consumers cannot be ruled out. Revisit if deployment integrations require these old public actions.
- [x] T017 [Plan:P4.1] Keep `bosend` send compatibility, `MailerSmtp`, and the `phpgwapi.mailer_smtp` factory mapping. Any further removal requires a separate approved change outside this module-only cleanup.

### Phase 5: Verify and Document

Plan item P5.1: demonstrate parity and removal completeness (REQ-005).

- [ ] T018 [Plan:P5.1] Run focused PHPUnit files individually: `EmailComposeSerializerTest`, `EmailComposePrefillTest`, `EmailOutgoingMessageTest`, `ModernImapMimeTest`, `EmailMessageContentTest`, `EmailMessageSerializerTest`, `EmailSearchCriteriaTest`, folder/filter/preferences service suites, and applicable `EmailMsgBootstrapTest` cases. Use actual existing paths under `tests/controllers/` and `tests/services/`.
- [ ] T019 [Plan:P5.1] Run `node --test tests/helpers/email-link.test.cjs`, PHP lint on touched files, JavaScript syntax checks on touched scripts, Twig validation for touched views, and `git diff --check`.
- [ ] T020 [Plan:P5.1] Recheck user-validated flows after removal: navigation/bookmarks, address picker, upload/delete, draft reopen/edit with attachments, reply/forward, send, search, print, filters, and default/extra-account preferences. Preserve `click_history` ordering and `/portico` prefix behavior.
- [ ] T021 [Plan:P5.1] Use isolated GreenMail folders/messages and `MAIL_TO_DISK` or fake transports. Do not clear the user's fixture mailbox or deliver external mail as part of cleanup validation.
- [ ] T022 [Plan:P5.1] Repeat the reference scan and check dispatch registrations: no active references to deleted files/classes/templates; no live legacy UI forms posting to retired handlers. Record retained compatibility paths and the reason for each.

## Backend Explicitly Out of Scope for Deletion

Keep `services/MailMessage.php`, `inc/class.mail_msg.inc.php`, `class.mail_msg_base.inc.php`, `class.mail_msg_wrappers.inc.php`, `class.mail_msg_display.inc.php`, `class.so_mail_msg.inc.php`, `class.msg_bootstrap.inc.php`, `IMAPManager.php`, the active IMAP wrappers/configuration, and transport classes used by enabled accounts. REST still depends on these classes, including MIME flattening and draft attachment restoration.

Keep the new MIME parser dependency and its tests. Do not combine this cleanup with a rewrite of message storage, account preferences, OAuth, transport backends, or session caching. Any unused backend/protocol candidate requires a separate usage assessment.

## Completion Criteria

- Every deleted item has a documented replacement or a confirmed absence of supported callers.
- Navigation and print menuactions remain usable as thin bridges.
- Twig views and REST endpoints no longer rely on deleted legacy rendering code.
- No retained constructor, hook, direct script, or test loads a deleted class/template.
- Legacy send/spell behavior is preserved wherever explicitly retained.
- Focused automated checks pass relative to the recorded baseline; manual confirmations are renewed for affected workflows.

Execute in small reviewable changes: navigation/search/list first, popup chains second, orphaned BOs/templates third, public-action hardening last. Do not delete all of `inc/` or `templates/` as one operation.

## Requirement Mapping

| Requirement | Plan items | Evidence |
| --- | --- | --- |
| REQ-001 | P1.1, P2.1, P4.1 | Retained navigation methods, preserved URL context, redirect checks. |
| REQ-002 | P1.1, P3.1, P4.1 | REST callers and CSRF checks retained; obsolete data dispatch retired. |
| REQ-003 | P1.1, P2.1, P3.1, P4.1 | Caller/constructor audit, staged class/template deletion, no unresolved references. |
| REQ-004 | P1.1, P3.1, P4.1 | Explicit retention or separately approved adaptation of the bosend/spell/compose chain. |
| REQ-005 | P1.1, P2.1, P4.1, P5.1 | Baseline, focused checks, workflow validation, final reference audit. |