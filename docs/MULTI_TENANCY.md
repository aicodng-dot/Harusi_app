# Multi-tenancy

Audit date: 2026-10-11. Related: [roles](ROLES_AND_PERMISSIONS.md), [database conventions](DATABASE_CONVENTIONS.md), [migration plan](SAAS_MIGRATION_PLAN.md).

## CURRENT — multi-event filtering, no workspace isolation

The application retains global event workflows. P3-002 prepares a Workspace model, workspace_users and nullable event ownership in source/tests; no tenant authorization or real backfill is enabled. [AdminEventController](../app/Http/Controllers/AdminEventController.php) lists/manages unowned events. [AdminUserController](../app/Http/Controllers/AdminUserController.php) manages only eligible unowned legacy staff accounts, excluding workspace/platform identities. Legacy admin role still does not supply customer authorization.

The admin session's `selected_event_id` selects list/report scope. [EnsureAdminEventSelected](../app/Http/Middleware/EnsureAdminEventSelected.php) now rejects invalid/owned explicit context and only auto-selects one safe active unowned event when the key is absent. It is still legacy navigation, not customer authorization. [AdminController](../app/Http/Controllers/AdminController.php) scopes guest/checkin/QR queries to that selection and checks guest-event equality, which helps avoid accidental cross-event edits by an admin. It is not customer isolation.

Scanner assignment is a single `users.event_id` plus `gate_name`. [ScannerController](../app/Http/Controllers/ScannerController.php) checks guest-event equality, and [MultiEventSupportTest](../tests/Feature/MultiEventSupportTest.php) covers wrong-event QR refusal and selected-event views. However, `scannerEvent()` falls back to selected/default event for missing assignments or a dangling event ID; it is not a fail-closed assignment resolver. Scanner admission does not explicitly require an active event status. Future behavior must be specified and tested before customer exposure.

Other boundary concerns: notification-log listing includes `event_id IS NULL` across selections; the admin layout event switcher lists only safe active unowned events; GET as well as POST can select an event; offline policies largely check admin role plus lifecycle, with selected-event checks in controllers. None supplies workspace membership enforcement.

## IMPLEMENTED — migration inventory support

P3-001 adds a CLI-only [legacy inventory](LEGACY_DATA_INVENTORY.md) with aggregate schema/core-integrity findings and explicit unresolved ownership/restore gates. The inventory itself creates no workspaces, memberships, owners or HTTP access. The configured/customer database remains unverified; completed [synthetic MySQL rehearsals](PHASE_03_REPORT.md) cover selected engine behavior only. Existing global-event administration is unchanged.

## IMPLEMENTED — dormant storage (2026-10-18)

P3-002 prepares workspaces(id/name/timestamps), workspace_users(required restrictive user/workspace links, owner/manager role, inactive default and unique pair), nullable indexed events.workspace_id and users.is_platform_admin default false. New relationships add no authorization or automatic ownership. Platform capability is hidden/non-fillable and excluded from existing HTTP/API inputs; legacy global admin/scanner roles remain unchanged. At the P3-002 gate the expected inventory covered 29 tables and the new columns; the current inventory covers 31 after the ownership-ledger additions. No default workspace, membership, event owner or platform grant is inserted.

Relations include active and inactive rows and convey no permission. Legacy staff authority remains; current selected context requires unowned events, and complete customer isolation is still unverified. Real ownership mapping and Phase 4 policies remain required before tenant exposure.

## TARGET — user-mandated workspace boundary

`User → Workspace Membership → Workspace → Events → Resources` is the customer ownership model. Workspace memberships support Owner/Manager; scanners receive separate event assignments. One identity can operate in multiple authorized contexts. Scanner-only users must not gain broad workspace management merely to scan an assigned event.

Every operation must validate account state, relevant membership/assignment, action permission, event ownership and resource ownership. This includes route IDs, query parameters, hidden inputs, session context, file downloads, QR generation, imports/exports, reports, notification logs, offline packages and conflict resolution. Scope query construction and authorize the actual resolved records, including every record in bulk requests. Never trust a client-supplied workspace/event/user ID as evidence of permission.

## PLANNED — safe ownership migration

1. On a protected copy, inventory schema/history/counts and current event/scanner/guest/log relationships. Collect explicit ownership mapping; back up database, files and required encryption material and verify restoration.
2. Add `workspaces`, `workspace_users` and nullable `events.workspace_id` in an approved migration. Keep the current application operational during the transition.
3. Create a Legacy Workspace and reviewed memberships. Backfill events idempotently without rewriting their IDs, guest QR tokens or manual codes. Do not infer a legitimate owner solely from the existing global admin role.
4. Verify every event's workspace and child-event consistency; investigate orphan and contradictory event IDs, eventless messages and deleted actor links. Compare counts/identifiers/admission totals to the baseline.
5. Introduce event scanner assignments with a deliberate migration from `users.event_id`/gate. Keep offline session-user snapshots, user IDs and active package behavior compatible; migrate all readers before retiring legacy columns.
6. Only after verification, tighten nullability/foreign keys and switch authorization to membership/assignment policies. Validate parent-child consistency with database constraints where feasible and guarded transactional writes in all paths.
7. Run two-workspace isolation tests for ordinary, bulk, file, QR, report, notification and offline operations; test URL/session manipulation and revoked memberships/assignments. Open public registration only after these gates pass.

No tenancy migration was performed by the audit. Phase 2 authentication must not silently ship tenant tables or customer access.

## REQUIRES VERIFICATION — decisions and proof

Approve Legacy Workspace owner mapping, orphan handling, memberships for existing staff, support access, event archive/cancel semantics, account/assignment revocation during offline sessions, retention/deletion rules and workspace-consistency constraint design. Cascading workspace deletion is not approved: existing admissions, checkins and offline provenance have mixed cascade/null/restrict behavior.

Production schema/data and behavior on the customer database remain unverified; completed synthetic MySQL rehearsals provide bounded evidence only. Dormant workspace ownership storage, backfill preparation and authority-context observation exist in source/tests. Current multi-event tests cannot prove customer isolation because runtime workspace membership and resource enforcement are not enabled.

## IMPLEMENTED — P3-003 source/test update (2026-10-18)

P3-003 validates explicit private workspace owner/event/platform proposals with strict bounded JSON and aggregate schema/eligibility/coverage observations. It never infers owners, assigns events, activates membership or changes existing authority. Every result keeps rollout_ready=false and real mapping/grant/restore/engine gates unverified. [Guide](OWNERSHIP_MAPPING_PREFLIGHT.md), [evidence](PHASE_03_REPORT.md).

## IMPLEMENTED — P3-004 source/test update (2026-10-18)

P3-004 prepares private exact-proposal identity and dormant workspace resolution. Changed manifests require separate reconciliation; workspace names are not keys and ledger rows do not establish legitimate ownership or activation. No writer or tenant access is enabled. [Guide](OWNERSHIP_LEDGER.md).

## IMPLEMENTED — P3-005 source/test update (2026-10-18)

P3-005 prepares one group per transaction and verifies exact inactive membership/event/resolution state before skipping a committed group. Partial retries can create remaining groups; changed proposals cannot adopt existing owned targets. No tenant policy, customer access or actual rollout is enabled. [Guide](WORKSPACE_BACKFILL_PREPARATION.md).

## IMPLEMENTED — P3-008 dormant authority context (2026-10-19)

The dormant resolver can observe exact active membership for a requested workspace, including fresh revocation and missing/deleted parent refusal. It never chooses context by name/session/default, grants a platform bypass or scopes/authorizes resources. Observed facts are not authorization or an unforgeable token. Current admin/scanner account-role compatibility is preserved; Manager action permissions, platform support bypass, ownership transfer/last-owner rules and runtime tenant enforcement remain undefined. Visibility follows the explicit connection and caller transaction/isolation; fresh queries do not fence concurrent changes or invalidate an already retained context. Real ownership/platform approval, customer restore, membership activation, maintenance fencing and Phase4 action/resource policies remain open. Phase3 remains IN PROGRESS. [Scope and verification](AUTHORITY_CONTEXT.md).

## IMPLEMENTED — P3-022 automatic new-workspace ownership

The customer who creates a NEW workspace becomes its initial active owner through one transaction, with a private creator-scoped replay receipt. No developer manifest is needed for that creation. Existing same-named workspaces/events are never adopted; unknown legacy events stay private. The [dormant provisioner](WORKSPACE_PROVISIONING.md) has no runtime caller and does not implement action/resource authorization. Existing global admin and scanner behavior is unchanged. Tenant policy and safe customer identity remain prerequisites for portal exposure.

## IMPLEMENTED — P4-001 selected action/parent boundary

The [dormant access reader](WORKSPACE_ACCESS.md) recognizes exactly five workspace/event actions and checks active membership plus an explicit event parent. Unknown/unowned/foreign resources deny. No existing controller, global scope, file route or scanner workflow uses it yet; complete tenant isolation is not claimed. Future mutation authorization cannot retain these point-in-time booleans across revocation. 976 tests / 17,366 assertions passed in isolation; actual rollout gates remain open.

## IMPLEMENTED — P4-002 exact child lineage

[GuestAccess](GUEST_ACCESS.md) verifies the complete requested workspace/event/guest and QR/guest identity on the primary after fresh membership/event observations. A resource belonging to a different guest in the same event still denies. No route or file-serving path uses the service; it is a source foundation, not deployed tenant isolation or a mutation fence.

## IMPLEMENTED — P4-003 own-membership home

The neutral customer mode now has one reachable read-only page, with primary own user/membership/workspace predicates and independent layout. It does not activate dormant event/guest readers or new workspace writers. Account roles, membership roles and platform flags stay separate; source staff CRUD excludes customer identities. Remaining global event/file surfaces must be closed before customer event exposure. [Contract](CUSTOMER_WEB_IDENTITY.md).

## IMPLEMENTED — P4-004 legacy catalogue/direct metadata slice

Legacy event catalogue and statistics now include only events with workspace_id exactly null and SQL-bounded event IDs from 1 through PHP_INT_MAX before Eloquent hydration. The admin layout uses primary filtered event queries for its selected title and active switcher; invalid/noncanonical selected IDs are ignored without rewriting the session. Direct edit/update/select/archive/cancel reread the event on the primary and return 404 for workspace-owned targets before validation or navigation changes, even when the admin is a workspace owner or has the platform flag. Bound raw identities must be canonical positive PHP-range integers before casting. Update/archive/cancel repeat null ownership on a locked fresh row inside the mutation transaction. Legacy create/update ignore submitted ownership and preserve ordinary unowned controls; no global Event scope or ownership reassignment was added.

This is only the catalogue/layout and direct event metadata slice. EnsureAdminEventSelected, AdminController::currentEvent, direct offline session routes, account event choices/assigned scanner administration, defaultEvent, child-resource/import/export/report/file/static-QR and scanner paths remain next-task boundaries. Filtering the layout neither authorizes those paths nor revokes existing selected context; read/selection observations do not fence concurrent reparenting. The composer's existing offline-session metadata service still uses default reads, so the entire layout is not proven replica-independent. Reassignment tests are controlled SQLite fixture schedules, not actual MySQL concurrency. SQLite cannot store MySQL unsigned overflow IDs; the upper query limit is verified through bound SQL parameters, not an actual MySQL overflow catalogue case. Customer writers/signup remain closed. Actual configured database/schema, legacy mapping/restore/fencing, MySQL provisioning, hosting/SMTP and browser/device checks remain unverified. The prepared workspace_id migration must exist before these filtered queries can run; no permissive fallback to an unknown schema is added. No real data/configuration/service/account/grant/file/dependency/build change or deployment occurred. [Reference](LEGACY_EVENT_ADMINISTRATION.md).

## IMPLEMENTED — P4-005 legacy context entry

A shared LegacyEventResolver accepts only positive PHP integers or canonical decimal strings within PHP range, reads the primary for exact null-owned events and verifies raw identity/ownership before returning a model. Missing/error/unsupported targets return null without writes, logs or default creation. soleActive uses a primary null-ownership/active/ID-range query capped at two and succeeds only for exactly one safe event. Explicit selected keys, including null, must resolve safely; invalid/missing/deleted/owned targets clear selected_event_id and the entire guest_import state and redirect without silently switching events. Only an absent key may auto-select the sole safe active legacy event; absent context also clears stale import state when no choice is possible. Valid selected legacy lifecycle states remain compatible.

AdminController independently checks current event and import preview again before using resource or cached import context; lost/owned/ambiguous state returns404 and clears selection/import without defaultEvent fallback. Direct offline create/store resolve the bound raw event ID afresh before context mutation, validation, snapshots or package generation, and use the returned current model. Private/missing/unsafe bound targets404 without context or package effects. Explicit event creation/selection/current clearing and valid offline entry discard guest_import on an actual context change, preserve it for canonical same-event navigation and retain unrelated workspace/session state. No customer or platform/member bypass is introduced.

These are request-entry and point-in-time context observations. Downstream guest/offline/file writers do not gain Event locks or atomic reparenting/revocation fences. Offline package/session/import/conflict lineage, file download/delete/audit ordering and relationship freshness remain separate work; unchanged helper/lock order and pretransaction audit effects are not certified. Global Event::defaultEvent and other model/scanner fallbacks, legacy staff/scanner event choices and public/static assets remain unchanged. The primary clone proves the resolver query paths, not every downstream relationship or offline metadata read. SQLite cannot insert MySQL unsigned overflow IDs; upper query bounds are checked through SQL bindings. Controlled post-gateway transitions are fixtures, not actual MySQL concurrency. Customer writers/signup stay closed. Actual configured database/schema, legacy ownership/restore/fencing, MySQL provisioning, hosting/SMTP and browser/device/bilingual visual checks remain unverified. No real data, environment/app key, account/owner/grant, service, dependency or existing build was changed, and no deployment occurred. [Reference](LEGACY_SELECTED_EVENT.md).

## IMPLEMENTED — P4-006 staff target boundary

Legacy staff account management now excludes every workspace-linked or platform target, including inactive membership rows and scanner/admin identities assigned to owned events. Targets require a safe canonical positive PHP-range raw user ID, exact admin/scanner role, known-false raw platform flag, no membership of any state and either null assignment or an actual safe unowned event. The primary catalogue applies these predicates before pagination and constrains its eager event relationship to safe unowned parents. Direct read/write targets are refreshed before validation; an actor's platform flag or memberships never bypass the target boundary. Inactive ordinary legacy accounts remain manageable.

Event options use primary active/null-workspace/positive-range filters with byte-exact status. Nonblank scanner assignment must be a canonical positive PHP integer or decimal string reaching the controller and resolve to a fresh active unowned event. Shared HTTP trim/empty-string middleware still runs first. Missing/empty assignment selects only the sole eligible active legacy event; zero or multiple candidates produce the same generic event_id error. No controller defaultEvent call or event creation remains. Admin assignments/gates are cleared as before; role/customer/platform/member input cannot grant additional authority.

Store explicitly supplies the eligible scanner event and locks its parent before inserting the User, avoiding the unchanged model default hook. Update/delete capture the old assignment, lock deduplicated old/proposed Events in exact ascending integer order, then lock User. They recheck raw identity, exact role/false platform flag and unchanged old assignment, followed by a current locking membership-row read. Assignment drift denies instead of taking a newly discovered Event lock after User. Only then do account/credential/token writes occur in the same transaction. Existing password hashing, remember-token rotation on password reset, token revocation and rollback remain intact.

This source task follows live offline package Event-before-User ordering and current compliant membership writers' User-before-membership protocol; it does not prove actual MySQL blocking or protect arbitrary external SQL/future writers. The dormant legacy backfill still requires its maintenance fence. Primary list/edit queries are point-in-time observations; the mutation locks cover these staff writes only. Integer PK/FK schemas prepared by repository migrations are assumed for SQL list filtering; raw bound identities are checked separately. SQLite cannot exercise MySQL unsigned overflow values or collation/locking behavior. Global model/scanner defaultEvent fallbacks, device/recovery code, scanner admission and remaining guest/offline/file lineage/write/audit/download/delete boundaries stay unchanged. Customer writers/signup remain closed. Actual configured database/schema, legitimate legacy ownership/restore/fencing, MySQL provisioning and hosting/SMTP/browser/device checks remain unverified. No real account/credential/owner/grant/data/configuration/app-key/service/dependency/build change or deployment occurred. [Reference](LEGACY_STAFF_ACCOUNTS.md).

## IMPLEMENTED — P4-009 customer workspace setup (2026-10-23)

Existing authenticated customers now create an empty workspace through two protected HTML routes and automatically become its active owner. Exact customer role is rechecked under the creator lock before creation/replay; submitted ownership/global fields grant no authority. Matching retries add no rows and failed inserts roll back. Full isolated source gate: **1,275 tests / 22,874 assertions**. Event/guest writers, signup and complete tenant isolation remain closed; no configured data or deployment changed. [Setup contract and verification limits](CUSTOMER_WORKSPACE_SETUP.md).

## IMPLEMENTED — P4-010 offline parent request-entry checks (2026-10-23)

Offline session/package/import/conflict controller actions now resolve fresh exact selected unowned Event chains before policies, payloads, validation and effects. Preview and generated-package references are checked before file/form work. Full source gate: **1,324 tests / 23,668 assertions**, with 100 unchanged routes. These joined primary observations do not fence later transactions/files or certify nested guest/scanner references. Customer event/signup exposure and real rollout remain closed. [Scope and evidence](LEGACY_OFFLINE_RESOURCES.md).

## IMPLEMENTED — P4-011 locked legacy package generation (2026-10-23)

Package generation now locks the unowned Event, sorted eligible actor/scanner Users and current scanner memberships before snapshots or generation effects. Scanner choices/validation exclude workspace/platform identities; late parent/actor denial and private database-error feedback remain safe. Existing transaction/crypto/payload/compensation logic is retained. Full source gate: **1,372 tests / 23,870 assertions**. Actual MySQL schedules and postcommit/later offline/file boundaries remain separate. [Scope](LEGACY_OFFLINE_PACKAGE_GENERATION.md).

## VERIFIED — P4-012 package-generation engine checks (2026-10-23)

All **six actual MySQL 8.4.11 case groups / nine independent service workers** passed, including twelve serial observations and four exact row waits. Six fresh synthetic schemas each applied the frozen 28 repository migrations and contained 32 tables. The P4-011 **1,372 tests / 23,870 assertions** source gate is retained by exact application/test fingerprints; no new backend suite run is claimed for this TEMP/docs-only task.

Selected Event/User waits, a current membership read after a caller RR absence snapshot, committed production generation before transfer and real driver-failure row/file compensation passed. All configured data/services are preserved and the owned server/credentials are cleaned up. Postcommit and later offline/file boundaries remain separate. [Evidence and limits](MYSQL_PACKAGE_GENERATION_REHEARSAL.md).

## IMPLEMENTED — P4-013 locked session cancel/close (2026-10-23)

Existing administrator cancel/close effects now recheck the current unowned Event, eligible actor, exact Session parent and lifecycle conditions under the transaction locks. Immediate import parents and current imported/open-conflict state are checked before effects. Audit-write failures roll back and return generic feedback; internal generation/import transitions remain unchanged. Full source gate: **1,482 tests / 24,782 assertions**. Actual MySQL action schedules and other offline/file/postcommit paths remain separate. [Scope](LEGACY_OFFLINE_SESSION_ACTIONS.md).

## IMPLEMENTED — P4-014 locked package delivery (2026-10-23)

All three existing download paths now lock current unowned Event/actor/Session/Package rows through fully buffered file reading, metadata/audit and transaction completion. Current-export downloads recheck the pointer; historical downloads retain compatibility. Precommit delivery failures roll back delivery metadata/audits while preserving already-committed generation. Full source gate: **1,565 tests / 25,642 assertions**. File deletion, integrity, client receipt, commit uncertainty and actual delivery engine schedules remain separate limits. [Scope](LEGACY_OFFLINE_PACKAGE_DELIVERY.md).

## IMPLEMENTED — P4-015 archive metadata (2026-10-24)

Package/ResultImport archive metadata and audit now share current Event/actor/Session/resource checks and a transaction. The locked current parent is supplied to existing helpers; no file bytes are accessed or changed. Existing archive eligibility and history compatibility remain. Full source gate: **1,653 tests / 26,523 assertions**. Secure deletion, other offline/file actions and actual archive engine schedules remain separate. [Scope](LEGACY_OFFLINE_FILE_ARCHIVES.md).
