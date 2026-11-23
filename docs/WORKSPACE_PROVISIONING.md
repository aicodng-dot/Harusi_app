# Automatic workspace creation

## CURRENT — bounded customer setup (2026-10-23)

Existing authenticated customers now use [workspace setup](CUSTOMER_WORKSPACE_SETUP.md). The strict customer entry rechecks exact role under the creator lock before creation/replay; generic internal eligibility is preserved. New workspaces grant automatic active creator ownership; public signup and customer event/guest writers remain closed.

## TARGET — self-service responsibility (2026-10-21)

The account creating a workspace becomes its initial owner automatically. That customer may be a person, organization or event planner; the label grants no platform privilege. Customers manage their events and team through the planned portal. New workspace setup does not require a developer, an ownership file or an event planner chosen by the platform. Existing unmapped events remain private; old administrator/scanner roles, event names or knowledge of a QR code never establish ownership.

## IMPLEMENTED — P3-022 source foundation

[WorkspaceProvisioner](../app/Services/Tenancy/WorkspaceProvisioner.php) exposes provision(Connection, creatorUserId, name, requestKey). The creator ID must eventually come from authenticated identity, never a submitted account ID. The generic internal service supports fresh active customer/admin/scanner accounts and never creates an account. A safe customer account mode and tenant policies must precede runtime exposure; creating a customer as a legacy global administrator would be unsafe.

Inputs are a positive PHP integer creator ID, a nonempty UTF-8 name of at most255 code points after Unicode separator/space trimming, and a canonical lowercase UUIDv4 request key. Raw control/format characters are rejected before trimming. Request keys are replay identifiers, not passwords, ownership proof or authorization. Names are preserved after trimming; no Unicode normalization/case folding/name matching is applied.

A fresh active supported creator account is locked before its private creator/request receipt. Exactly three inserts commit together: a new workspace, active owner membership and provisioning receipt. Matching retries use zero DML and retain timestamps. Names are labels, not identity: different keys/accounts can create separate same-named workspaces. A renamed workspace survives retry without being renamed back. Changed creation payload, missing/disabled/demoted owner membership and missing parent refuse without resurrecting authority. Creator eligibility is reread on every call. Raw allocator IDs must be positive and fit PHP integers; SQL collation cannot turn a matching altered hash into an exact retry. Hash corruption that no longer matches a request cannot be distinguished from an unrelated key; this is not a general integrity audit. No automatic database conflict retry is attempted.

The explicit connection alone is used, including primary metadata/row reads. Supported transactional/FK-enabled schema, exact keys and allocators are checked before opening a transaction; ambient transactions refuse before queries. Same-creator MySQL requests are designed to serialize on the creator row. The original SQLite fixtures establish rollback/retry semantics; selected actual MySQL behavior is recorded in the P4-008 follow-up below. The creation receipt exposes getters only; JSON/debug output contains no workspace identity. A receipt is not a permission token.

## IMPLEMENTED — additive private ledger

[Migration](../database/migrations/2026_10_21_000000_add_workspace_provisionings.php) prepares an empty workspace_provisionings table: numeric allocator id, restrictive creator_user_id/users and workspace_id/workspaces links, request_hash/name_hash CHAR64 and timestamps. Unique(creator_user_id,request_hash) scopes replay identity to the creator; unique(workspace_id) prevents multiple creation receipts for one workspace. Domain-separated SHA-256 stores the request key and canonical original name fingerprints; hashes are private metadata, not encryption. The original name is not separately retained in the ledger.

Migration refuses existing/partial table or view and unsafe/missing parents. Empty reversal is supported; any creation record blocks down before DDL. Keep populated structures during code rollback. DDL can be non-atomic; a partial deployed failure needs forward repair. No ownership/member/event data is added by the migration itself. Count-only preflight exposes table presence/count/missing-column names, never receipt contents.

## IMPLEMENTED — verification

Focused workspace/ownership checks passed **287 tests / 8,225 assertions** (24.943250s); the complete isolated backend suite passed **938 tests / 15,017 assertions** (145.771976s), with zero errors/failures/skips. Task-scoped Pint and PHP syntax passed. Registered routes remain 97, and the bounded count-only inventory now catalogs 32 tables.

Tests cover same-creator exact retries, cross-creator/key/name boundaries, rename conservation, account/member revocation, strict pre-connect inputs, primary/read/default traps, generated-ID guards, schema/foreign-key refusal and rollback after each completed insert. Migration checks cover empty up/down/reapply, no partial adoption, unique/restrictive constraints, populated-down refusal, ambient transaction refusal and private inventory. Complete fixture snapshots protect old data and schema; allocator gaps after rollback are not a portability guarantee.

## REQUIRES VERIFICATION — release boundaries

The source service has no HTTP/CLI/job caller and creates no accounts or events. Existing admin/scanner roles, platform flags, scanner assignments, credentials, QR/manual codes, admission/offline history and legacy ledgers remain unchanged. Source tests use invented data and isolated SQLite connections, array messaging/cache/session and TEMP storage. The configured database remains unavailable at the P3-021 diagnostic; no configured migration, real workspace/member grant, service/dependency/build change or deployment occurred. Actual MySQL provisioning contention/DDL, customer database/private-file restoration, rollout fencing, tenant action/resource policies, customer identity/portal and public registration are not established by this task.

No existing owner, platform operator or customer grant was inferred. Legacy backfill remains a separate reviewed recovery tool; it still creates inactive memberships. New creator-owned setup is deliberate and automatic within its transaction. Team/transfer/last-owner protections, account verification/abuse controls, event creation and scanner assignment remain future tasks. See [onboarding](SELF_SERVICE_ONBOARDING.md), [roles](ROLES_AND_PERMISSIONS.md), [phase evidence](PHASE_03_REPORT.md) and [project plan](PROJECT_PLAN.md).

## IMPLEMENTED — P4-003 customer eligibility

The dormant new-workspace service now accepts a fresh active customer account as well as the existing admin/scanner modes. The new identity tests prove a customer can receive automatic active ownership and exact zero-write retry on an owned SQLite clone, preserving account/global/scanner fields and legacy events. The HTTP customer home is read-only and does not invoke this writer. Actual MySQL provisioning/public setup and all rollout gates remain open. [Identity contract](CUSTOMER_WEB_IDENTITY.md).

## IMPLEMENTED — P4-008 actual MySQL follow-up (2026-10-23)

All **10 actual MySQL 8.4.11 cases / 13 independent service workers** passed, including 12 serial observations, three exact creator-row waits, interruption after each completed insert and three actual unsigned allocator cases. Eight fresh synthetic schemas applied the frozen 28 repository migrations and contained 32 tables. The complete P4-006 **1,240 tests / 22,336 assertions** source gate is retained by exact source/test preservation; no new backend suite run is claimed for this TEMP/docs-only task.

An active customer creates exactly one new workspace, one active owner membership and one private hashed receipt in one transaction. Unicode-trimmed matching retries return the same workspace with zero DML. Changed payloads conflict; same-named workspaces are never adopted, and keys are scoped to the creator. Renames survive replay. Disabled/demoted owners, disabled creators and collation-equivalent corrupt request hashes refuse without repair or resurrection. Existing accounts, global roles/platform flags, event assignments/history and legacy ledgers remain conserved. Explicit primary metadata and row reads succeed with an unavailable read PDO and unconfigured global default; no replica attempt occurs. Receipt JSON/debug privacy also holds.

| Case group | Actual engine evidence |
|---|---|
| Serial creation and replay | Twelve observations verify exact three-row setup, normalized retry, creator/key/name separation, rename/revocation/hash refusal and explicit-primary behavior. |
| Same creator and exact request | The contender waits on users PRIMARY 221. One call commits three inserts; the second returns the same workspace with zero DML. |
| Creator revocation in both orders | Revocation-first refuses creation without setup. Creation-first commits its setup, then deactivation commits; a later retry refuses without resurrecting authority. |
| Worker interruption at three stages | Verified owned workers are terminated after workspace, owner-membership or receipt insert. Thread disappearance, exact complete row rollback and successful exact-request retry are verified separately at each stage. |
| Actual unsigned allocator IDs at three stages | Separate table allocators produce raw 9223372036854775808. Workspace/member/receipt stages refuse invalid_prerequisites after the expected 1/2/3 inserts, roll back every new row and return no unsafe receipt. |

A new ACL-restricted TEMP MySQL data directory used only loopback 43495, MySQLX disabled, generated scoped credentials and the read-only Oracle-signed binary. UUID/datadir/port/bind/version were verified before fixture writes. Three waits matched both worker connection IDs and the exact users/PRIMARY/221 WAITING RECORD. Full row snapshots and table definitions were compared with only AUTO_INCREMENT counters normalized; ID reuse or allocator restoration is not claimed. All owned workers/server are stopped, the port is free, safe unavailable error 2002 was confirmed, and the four generated plaintext credential files were removed. Harusi's configured database, XAMPP and other services/data were untouched.

This is selected synthetic MySQL evidence, not a configured/customer database rollout or every possible concurrency schedule. The provisioner remains dormant with no HTTP/CLI/job application caller, public signup, customer account creation or event/resource writer. Future callers must derive creator identity from authenticated state and apply current action/parent authorization; request keys and creation receipts are not permission tokens. Legitimate legacy ownership/platform approval, real database/private-file restore/fencing and hosting/SMTP/browser/device remain unverified. Remaining scanner/guest/offline/file boundaries and customer event/signup exposure are still open. No application/test/migration/API/UI/default/model/credential/owner/grant/dependency/build/environment/app-key or real-data change or deployment occurred. [Engine scope](MYSQL_WORKSPACE_PROVISIONING_REHEARSAL.md).

## IMPLEMENTED — P4-009 customer workspace setup (2026-10-23)

Existing authenticated customers now create an empty workspace through two protected HTML routes and automatically become its active owner. Exact customer role is rechecked under the creator lock before creation/replay; submitted ownership/global fields grant no authority. Matching retries add no rows and failed inserts roll back. Full isolated source gate: **1,275 tests / 22,874 assertions**. Event/guest writers, signup and complete tenant isolation remain closed; no configured data or deployment changed. [Setup contract and verification limits](CUSTOMER_WORKSPACE_SETUP.md).
