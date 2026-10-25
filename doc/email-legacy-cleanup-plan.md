# Email Legacy Cleanup Plan

Status: cleanup implemented in the working tree; external consumers cannot be verified from this repository.

This document began as a proposed plan. The execution record below supersedes the original no-deletion status; unchecked tasks remain open and must not be treated as completed.

## Execution Update

- Consolidated validation ran all 41 mail/network/controller test files independently with GreenMail enabled. After updating the search-controller query double to the new `where()` API and removing its incidental flag-order dependency, 39 files exit successfully (one includes a skipped spellcheck test). Bootstrap retains two previously known failures: selected-part body-cache persistence and a fetchbody flags expectation (`''` versus `0`). Outgoing-message tests cannot load the removed legacy `class.bosend.inc.php`. Overall validation is therefore not green; these unrelated baseline issues remain open. Successful suites include the live GreenMail lifecycle and local POP3/TLS flows; native POP3 and real OAuth remain unverified.

- Modern search now consumes the entire supported application criteria string instead of independently matching a few regex fragments. It handles all flags/text fields emitted by EmailSearchCriteria, escaped quotes/backslashes, case-insensitive operators, and ON/BEFORE/SINCE dates in application `m/d/Y` or IMAP `d-M-Y` form. Malformed/unsupported criteria explicitly fail rather than silently omitting restrictions; arbitrary IMAP boolean/group syntax remains unsupported. Offline exact-operand tests and GreenMail application-generated combined text/flag/date queries verify filtering and no-match cases.

- Modern whole-body and MIME-section reads now honor FT_PEEK: ordinary reads mark Seen and invalidate cached flags; PEEK reads preserve unread state. Shared header/structure lookups retain their existing non-marking behavior. Offline command-shape tests cover UID/sequence and PEEK/non-PEEK combinations; GreenMail verifies actual Seen/Unseen state and header-cache refresh after body reads.

- Modern close honors CL_EXPUNGE (32768) using the IMAP CLOSE command, which expunges and deselects the mailbox before logout. Ordinary close remains logout-only. Rejected CLOSE returns false with diagnostics and retains the handle for recovery. Offline tests verify command selection/failures; GreenMail verifies deletion marks survive ordinary logout and disappear after flagged close. An initial EXPUNGE-then-logout attempt encountered the existing GreenMail stale-listener cleanup bug, leaving one additional blocked temporary folder (`lifecycle-83ac71b19f8994af`); the final CLOSE implementation cleans up successfully without restarting or resetting seeded mail.

- Modern mailbox listing now delegates reference names and wildcard patterns to server LIST rather than filesystem `fnmatch()`. Fully qualified server prefixes are separated from the IMAP reference path and restored on returned names. Both listing APIs share the same result conversion, preserving server delimiters (including NIL) and standard mailbox attribute bits. Offline tests cover reference/prefix handling and attributes; GreenMail nested-folder tests verify `%` versus `*`, nonempty reference paths, and recursive cleanup.

- Modern subscribe/unsubscribe now issue Webklex SUBSCRIBE/UNSUBSCRIBE commands and validate the server response rather than returning simulated success. Fully qualified mailbox prefixes are removed while encoded mailbox paths are preserved. Offline tests cover both operations, plain/qualified paths, and rejected replies; GreenMail LSUB checks verify state changes and temporary-folder cleanup.

- Modern open now resolves and selects the requested mailbox before publishing its connection handle. Missing mailboxes and connection/lookup/selection exceptions disconnect the provisional client and leave active connection state unchanged. Cleanup exceptions are explicitly reported alongside the original failure without including their potentially sensitive message. Offline failure-stage tests and a GreenMail missing-folder flow verify cleanup and preservation of an existing healthy session.

- Modern deletion now validates and sends complete IMAP message sets via one mark-deleted STORE command, preserving comma lists, bounded/reversed ranges, wildcards, and UID mode without downloading message bodies or implicitly expunging. The lower-level Webklex command API is necessary because its `store()` bounds accept only integers, not `*`. Malformed sets fail before server commands. Offline tests cover command shape/rejection; GreenMail verifies sequence and UID ranges plus the active `empty_trash()` `1:*` path.

- Modern search now returns sequence numbers by default and UIDs only with SE_UID. Header-prefetch identifiers follow the requested mode, and header cache keys include mailbox path and UID/sequence mode. Reopen, rename, flag updates, deletion, move, and expunge invalidate message metadata. Offline identifier regressions and a GreenMail flow deliberately diverge UIDs from sequence numbers, verify cache mode collisions, flag refresh, and folder switching. The live test creates and removes isolated folders without changing seeded INBOX messages.

- Expanded opt-in GreenMail checks for flag set/clear, UID copy/move, explicit deletion/expunge, and reconnect persistence using isolated temporary folders. Fixed modern search handling for FLAGGED/DELETED and changed modern deletion to mark only rather than immediately expunging; failed deletion marking now returns false with a diagnostic. Offline regressions cover these changes. Reconnect testing selects INBOX before logout to avoid a GreenMail 2.1.2 stale-listener exception. Three folders from initial failed runs (`lifecycle-dc0c9ec69a530aa3`, `lifecycle-09d34b5c5c8f6f1e`, `lifecycle-1e811801a4a2aabb`) remain blocked by that server-side exception; targeted cleanup failed and GreenMail was not restarted, preserving seeded data.

- Implemented socket POP3 connection checks with NOOP and a token-boundary-validated positive response. Healthy checks leave the session available; rejected, malformed, disconnected, or already-closed connections return false with network diagnostics and close locally without QUIT, avoiding unintended deletion commits. Real loopback tests cover both positive response forms, negative and malformed replies, EOF, post-close checks, and subsequent commands on healthy sessions.

- Fixed modern IMAP message counts: Webklex `Folder::examine()` returns an array, not an object with `exists`. Counts now reuse STATUS/MESSAGES, returning integer zero for empty mailboxes without switching the selected mailbox to read-only via EXAMINE. Missing counts fail explicitly. Unit regressions and the live GreenMail temporary-folder test cover empty and populated mailboxes; this resolves the count issue recorded in the preceding append batch.

- IMAP transport `append()` now returns the backend boolean instead of discarding it. Regressions cover both backend results. An opt-in GreenMail test verifies IMAPS append success, header/body read-back, rejection for a nonexistent mailbox, and cleanup of its uniquely named folder without modifying seeded INBOX messages. During validation, `imap_num_msg()` returned false for the selected test folder; the append test uses SEARCH to verify message count instead. That separate message-count issue remains open.

- Added local POP3S/TLS checks: a trusted loopback certificate permits an encrypted login/STAT/QUIT session; an untrusted certificate and a trusted certificate with the wrong hostname reject the connection before authentication. Tests generate short-lived certificates/private keys in owner-only temporary files, set process-local OpenSSL trust, restore the environment, and delete the files. Production verification policy is unchanged; real-server and native-transport interoperability remain unverified.

- Added real loopback TCP tests against a disposable scripted POP3 server. They cover login, STAT, TOP sorting, folded headers, RETR/dot unstuffing and long lines, cache command counts, DELE/QUIT, rejected authentication, failure disconnect without QUIT after DELE, and truncated-response cache rejection. The tests use synthetic credentials and an OS-assigned loopback port; they do not validate real-server deletion persistence, native POP3, IMAP/OAuth, or POP3S/TLS interoperability.

- POP3 operational error handling now closes the local socket and throws without HTML rendering or process exit. It deliberately avoids QUIT on failure to avoid committing pending deletions. Sorting uses the validated TOP/header-array path, including unfolded case-insensitive fields, missing-field handling, and reuse of the existing single-message header cache.

- Removed the unreferenced `class.mail_dcom_imap_sock.inc.php`, `class.mail_dcom_nntp.inc.php`, and `class.mail_dcom_nntp_sock.inc.php` implementations after checking repository loaders and callers. The active factory selects only IMAP/IMAPS and POP3/POP3S; the shared socket base remains required by POP3. Updated obsolete troubleshooting guidance. Residual news-mode compatibility branches and port defaults were not removed; external direct includes cannot be verified. Transport selection and POP3 dot-unstuffing are now complete in automated checks; live-server/TLS validation remains pending.

- POP3 multiline paths now remove exactly one transport-added leading dot after checking the terminator. RETR headers/bodies, TOP headers, and sorting reads opt in; shared reader defaults remain unchanged. Cached content is already unstuffed and is not decoded again. Byte-exact regression tests cover leading dots, whitespace, blank lines, and repeated reads.

- POP3 socket login now validates the positive greeting, returns connection/authentication failures without rendering HTML or exiting, and retains diagnostics for the mail core. Failed logins close their socket; QUIT always closes locally, including failed/throwing commands, and repeated close is safe. Live POP3/TLS verification and dot-unstuffing remain pending.

- Added email-specific object-factory routing so transport selection uses each account's explicit protocol rather than include-time default preferences. IMAP always uses the IMAPManager adapter (modern/OAuth with native fallback); POP3 retains native support when available and the socket fallback otherwise. Distinct POP3/socket-base class names allow mixed-protocol accounts. Native POP3 opens directly through the native wrapper; socket POP3S uses an SSL connection. Unsupported protocols now fail explicitly rather than falling through to IMAP; NNTP implementation files remain untouched except the socket parent-class reference.

- POP3 body retrieval now caches the raw headers together with the completed body, avoiding repeat RETR requests while preserving folded header bytes. Reopening clears message caches; incomplete headers/bodies are rejected before cache publication. Transport selection remains pending.

- Socket multiline readers now require an exact terminator (ignoring CRLF only) before reporting success. Interrupted responses throw rather than being cached or parsed as complete data; partial parsed headers are cleared and existing network diagnostics retained. POP3 dot-unstuffing and transport selection remain separate work.

- Repaired retained socket-base initialization to call the current network parent constructor. Fixed encoded-header callbacks to decode individual payloads from the supplied string without adding apostrophe escapes, and repaired malformed display-name cleanup expressions; retained legacy byte/charset behavior and header-dispatch policy. Constructor, encoded-word, and address-parser regressions are covered without live connections. Transport selection and POP3 cache work remain pending.

- Transport scope confirmed: retain IMAP/IMAPS/OAuth and POP3/POP3S; NNTP modernization is out of scope. First shared-network batch reads complete lines, detects read timeout/EOF, finishes partial writes, safely closes sockets repeatedly, and corrects response-code parsing. Binary byte-count and CRLF behavior are retained. Transport selection, retained socket repairs, and POP3 cache optimization remain follow-up work.

- Simplified bootstrap login-policy assignments and removed a temporary variable. Documented and tested the legacy boolean/integer return distinction, unsupported-input fallback, and direct public login setting before request initialization; no login-policy changes were introduced.

- Draft/send enabled-account validation now shares a single-pass `EmailAccountDirectory::hasEnabledAccount` lookup, avoiding intermediate filtered/mapped arrays and stopping at the first match. Existing membership rules and response behavior are preserved.

- Compose, draft, and send cleanup now delegate request closure to `MailMessage::releaseInstance` instead of closing twice. Nested `finally` blocks restore temporary request globals even when mail cleanup throws; cleanup failures remain visible.

- Removed the unused private widget property from `email_msg_bootstrap` and documented its shared-request lifecycle and API/browser failure behavior. Removed account numbers incorrectly passed as legacy debug levels by five controller calls; account selection remains in request parsing and explicit mail operations. Login modes, compatibility constants, and return behavior were retained.

- Build the compose URL once per nonempty body in `make_clickable`, rather than once per text line. Regression coverage preserves multiline URL/email link output, folder encoding, trailing newlines, and the empty-body fast path.

- Reduced repeated work in `get_msg_list_display`: reuse the common date-formatting service within each list, read `show_addresses` once per row, and remove an unused settings lookup. Preferences and message references remain row-specific, including mixed-account lists.

- Removed the unreferenced `fill_sort_order_start_msgnum` compatibility alias after a repository-wide caller search. Initialization continues to call `fill_sort_order_start` directly; active formatting, MIME, and navigation helpers were retained.

- Removed the unreferenced `all_folders_listbox`, `folders_mega_listbox`, and `all_ex_accounts_listbox` HTML selector renderers from `class.mail_msg_display.inc.php`. Folder/account data services and active Twig selectors remain in place; MIME and message-navigation methods were retained.

- Removed the unreferenced `report_moved_or_deleted` formatter and obsolete `minimum_version` PHP-version helper from the mail core. Active REST action reporting and MIME/message processing remain unchanged.

- Removed unreferenced mail-core HTML/debug helpers (`href_maketag`, `href_maketag_class`, `img_maketag`, `array_keys_str`, and `_image_on`) after a repository-wide reference search. Removed unreachable output after the connection-recovery early return; active retry behavior remains unchanged.

- Removed the obsolete email popup entry points, UI/business-object implementations, spellcheck chain, and unreferenced legacy screen templates.
- Reduced the retained menuaction classes to Twig navigation bridges and kept `bosend.send()` for confirmed send compatibility.
- Kept `bopreferences` as a legacy request/presentation adapter; preference definitions, defaults, persistence, and cache invalidation now use shared services.
- Removed `inc/class.html_widgets.inc.php` after replacing its home-screen folder form with `EmailHomeSummary` and `html/base/home-summary.twig`, and its remaining connection-error renderer with `html/base/connection-error.twig`. Repository searches found no remaining runtime callers.
- Retained `error_report.tpl` and both `widget_toolbar.tpl` files pending a separate reference audit; their former `html_widgets` loaders have been removed, but this does not establish that all related assets are unused.
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
| `inc/class.html_widgets.inc.php` | Removed. | Home-screen and connection-error rendering migrated to Twig; no remaining repository runtime references found. |
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
- [ ] T015 [Plan:P4.1] Audit `app_data*.xsl`, legacy JavaScript, images, and template-set registrations against remaining runtime loaders and hooks, including assets formerly loaded by the removed `html_widgets`. Delete only proven orphans; retain translations, help, setup, and shared assets by default.
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