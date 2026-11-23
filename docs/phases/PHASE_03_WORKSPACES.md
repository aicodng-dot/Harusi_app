# Phase 3 — workspace and ownership migration

## CURRENT — execution boundary

Phase 2 repository gate is complete. P3-001 inventory, P3-002 dormant workspace storage and P3-003 explicit proposal preflight are complete in source/tests. Actual ownership, authorization and rollout remain open. Current MySQL schema/data, backup restore and legitimate owner/platform mapping are unverified; read-only tools do not certify the real database or authorize backfill.

## P3-001 — read-only legacy inventory/preflight

Status: COMPLETED in source/tests (2026-10-18).

Objective: expose a repeatable CLI-only aggregate inventory that identifies migration prerequisites and core integrity findings without mutating existing records or exposing secrets.

| Field | Contract |
|---|---|
| Dependencies | Phase 2 repository gate; migration/model/schema review; explicit current ownership and backup gaps |
| Files | New TenancyPreflight command, LegacyDataInventory service, focused tests, canonical/specialist evidence docs |
| Database/API/UI | No schema/data change, new HTTP route or UI; CLI requires an explicitly named configured connection |
| Supported engines | SQLite and MySQL/MariaDB connections through Laravel; actual MySQL behavior remains unverified until a real rehearsal |
| Output | Versioned JSON on stdout; engine/version, expected-table presence/counts/required-column gaps, migration counts/pending known names/unknown count, selected unique-index checks, named aggregate findings and explicit limits |
| Privacy | No names, emails, phone values, IDs, tokens/codes, password hashes, payloads, file/database paths, credentials, raw SQL/errors or unknown migration names |
| Safety | Query builder/schema reads only; no Eloquent saves/defaultEvent, migrations, seeding, repair, inferred ownership, file writes or implicit default connection |
| Exit codes | 0: complete bounded inventory with no findings; 1: complete with findings requiring review; 2: unavailable, unsupported, incomplete schema or invalid invocation. No code means ready for rollout |
| External gates | Always state ownership_mapping, backup_restore and mysql_rehearsal unverified; rollout_ready=false. Best-effort observation, not a consistent database backup or atomic snapshot |

Acceptance criteria:

- Reject missing/unknown connection names before connecting; sanitize unavailable-engine/query failures into nonzero JSON without exception details or logs.
- Inventory known application/auth/framework/offline tables and local migration history without installing a missing migration repository. Detect missing required columns and pending/unknown migrations.
- Check core guest/event/QR/admission references, four-digit string codes, duplicate code/QR relationships, allowed pass/counter bounds and selected unique indexes; identify unassigned/dangling scanner accounts and nullable notification/history review counts.
- Inventory offline tables as aggregate counts; explicitly keep exhaustive offline provenance/constraint audit and file verification outside this bounded task. Never treat preserved conflicting log identifiers as automatic corruption, compare historical scanners to current assignment, or derive used counters from admission sums.
- Bound row streaming where SQL-portable checks need it. Treat missing prerequisites as incomplete/skipped checks, never a false clean report.
- Prove clean fixtures, malformed/incomplete schema, pending/unknown migrations, invalid/unavailable connections, orphan/inconsistent data, optional historical nulls, privacy, repeatability and unchanged database contents. Verify no default event creation.
- Run focused/full isolated regression, command discovery, formatting/syntax/whitespace and preservation checks; update the plan with dated objective, acceptance and evidence.

## IMPLEMENTED — P3-001 evidence

Full isolated regression passed **378 tests / 3,963 assertions** (148.420344s). Focused inventory checks passed **22 tests / 846 assertions** (1.284131s). Command discovery, missing-connection and empty-schema CLI smoke checks passed with their required nonzero exits; registered HTTP routes remain 97. New-file Pint, PHP syntax and scoped whitespace checks passed. All pre-existing source/data/config/assets remain preserved. The configured MySQL connection is unavailable (sanitized exit 2), so live schema/counts remain unverified. [Operator guide and report meanings](../LEGACY_DATA_INVENTORY.md), [phase report](../PHASE_03_REPORT.md).

Review added raw MySQL index-part verification and unique/non-null streaming-ID prerequisites to prevent false-clean results; focused regressions cover both. Offline coverage remains counts only. Phase 3 remains IN PROGRESS.

## P3-002 — dormant workspace expansion

Status: COMPLETED in source/tests (2026-10-18). Depends on the completed P3-001 source/test gate. Actual configured MySQL remains unavailable, so this is source preparation and isolated verification only.

Objective: add minimal workspace/membership structures and a separate protected platform capability without assigning existing event owners or changing legacy web/scanner authority.

| Field | Contract |
|---|---|
| Files | New 2026_10_18_000000_add_workspace_foundation.php migration and Workspace model; narrow User/Event relationships and platform cast/hidden field; inventory catalog and relevant tests/docs |
| workspaces | Numeric id, required name (255), timestamps; no owner_id, slug, billing, status or automatic default row |
| workspace_users | Numeric id, required workspace_id/user_id with restrictive FKs, required enum owner/manager role, is_active default false, timestamps; unique(workspace_id,user_id), index user_id |
| events | Nullable indexed workspace_id FK to workspaces with restrictive delete; existing/new legacy events remain null unless a later authorized writer explicitly sets ownership |
| users | is_platform_admin boolean default false; no inferred grants from global admin role or membership; cast boolean, hidden, excluded from fillable and all existing HTTP inputs/profile output |
| Models | Explicit workspace/user many-to-many relation through workspace_users, pivot id/role/is_active/timestamps; Workspace events relation and Event workspace relation. Relations are data access, not authorization; no global scopes, boot hooks or automatic membership/assignment |
| Inventory | Expand expected catalog to 29 tables, new ownership/capability columns and membership/workspace counts; remain a bounded diagnostic with rollout_ready=false |
| API/UI/access | No route/view/registration/role switch or tenant policy. Preserve existing global admin and scanner role checks; membership grants no platform/scanner access. Explicit platform enforcement/grants are a later reviewed task before customer exposure |
| Rollback | Check complete expected expansion and all guards before any DDL; refuse if any workspace/membership row, non-null event ownership or true platform capability exists. Empty reversal removes FK/columns before tables. Keep populated schema for code rollback |
| Database safety | No configured DB migration/seed/backfill/owner mapping. Refuse existing conflicting expansion rather than silently adopt it. Document non-atomic DDL/partial failure and actual engine rehearsal limits |

Acceptance criteria:

- Fresh isolated migrations create the declared nullable/default/unique/FK schema; no workspace/membership/platform grant/ownership backfill is created.
- A populated legacy upgrade preserves all prior record IDs, QR tokens/manual codes/counters, credentials/scanner assignments and offline event references. Exercise SQLite's event-FK table rebuild outside an active test transaction; do not rely only on fresh-schema proof.
- Reject duplicate memberships, unsupported roles, orphan links and deletion of referenced workspace/user parents; preserve records on failure.
- Empty expansion reversal/reapply works; each populated rollback guard refuses before schema or data changes. Unsupported/partial pre-existing expansion fails safely for review.
- Existing admins without membership retain legacy routes. Scanner workspace owners retain scanner-only authority. HTTP/admin/API inputs cannot grant is_platform_admin or set event ownership, and profile serialization excludes the capability.
- The named read-only inventory reports the expanded expected schema/counts without changing data or pretending ownership is verified.
- Run focused/full isolated regressions, formatting/syntax, route/command and conservation checks; update dated task evidence and relevant architecture/domain/DB/tenancy/role/deployment references.

Limits: schema preparation is not tenant isolation. Source/tests do not prove real MySQL/MariaDB DDL, restored backups or legitimate owners. Keep registration/customer endpoints closed; actual inventory, restore rehearsal and reviewed mapping must precede rollout/backfill. New restrictive links will require deliberate future retention/user-management workflows; this task introduces no membership-management UI.

## IMPLEMENTED — P3-002 evidence

Full isolated regression passed **410 tests / 4,249 assertions** (111.846082s). Focused workspace/inventory checks passed **54 tests / 1,132 assertions** (2.762719s). Eight task PHP files passed Pint and syntax checks; scoped whitespace checks passed. Runtime retains 97 HTTP routes, and the empty-database CLI returns incomplete/exit 2 with the expanded 29-table catalog.

P3-002 prepares workspaces(id/name/timestamps), workspace_users(required restrictive user/workspace links, owner/manager role, inactive default and unique pair), nullable indexed events.workspace_id and users.is_platform_admin default false. New relationships add no authorization or automatic ownership. Platform capability is hidden/non-fillable and excluded from existing HTTP/API inputs; legacy global admin/scanner roles remain unchanged. The expected inventory now covers 29 tables and the new columns. No default workspace, membership, event owner or platform grant is inserted.

Populated legacy upgrade and rollback guards preserve record contents/identifiers/offline references. Review added rejection of unknown null platform values. The initial focused run exposed the indexed-column rollback order; explicit FK/index/column removal now passes. SQLite rebuilds are refused within an active transaction, and the migration declares withinTransaction=false. No actual MySQL migration was run. [Phase report](../PHASE_03_REPORT.md).

## P3-003 — explicit ownership/platform mapping preflight

Status: COMPLETED in source/tests (2026-10-18). Depends on the completed P3-002 source/test gate. Real owners, platform grants and backups cannot be inferred from current records; actual database remains unavailable.

Objective: validate a private explicit JSON proposal and report bounded database eligibility/coverage without creating workspaces, changing owners, activating memberships or granting platform capability.

| Field | Contract |
|---|---|
| Files | New OwnershipManifest value object, LegacyOwnershipPreflight read service, CLI command and focused parser/database/command tests; evidence docs |
| CLI | tenancy:ownership-preflight --connection=<configured-name> --manifest=<local-regular-file>; explicit connection only, JSON stdout, fixed read-only mode, no apply/write option |
| Manifest v1 | Exact top-level schema_version=1, workspaces list, platform_admin_user_ids list. Each workspace has exact reference/name/owner_user_ids/event_ids fields; references lowercase [a-z][a-z0-9_-]{0,63}, name nonblank UTF-8 max255 without controls, nonempty owners/events lists |
| Types/duplicates | IDs positive PHP integers, no strings/bools/floats/overflow; reject duplicate JSON object keys including escaped-equivalent names, repeated references, duplicate owners within group, repeated events anywhere and repeated platform IDs. Owners may join multiple workspaces |
| Resource bounds | Maximum 256 KiB JSON, depth8, 1000 workspaces and 10000 total ID occurrences; bounded file reads and DB batches <=500 IDs; reject URLs/stream wrappers, UNC paths and non-regular/unreadable files |
| Database | Required users/events/workspaces/workspace_users schema subset; users/events need single-column id primary keys and no observed null IDs. Selected owners must exist, active and supported admin/scanner role; proposed platform users must be explicitly listed active legacy admins |
| Event coverage | Every proposed event must exist and remain unowned. Every currently unowned event, including archived/cancelled/default events, must be explicitly covered; no automatic catch-all/default owner. Already-owned/missing/unmapped rows are findings |
| Existing flags | Explicitly requested platform capability already true is a review finding; no automatic inclusion of all admins and no revocation/change of unlisted flags |
| Output | Schema version1, status complete/incomplete, mode read_only, aggregate proposal/coverage/findings and known schema gaps only; no names, IDs, references, manifest contents/digest/path, account data, raw SQL/errors or logs |
| Exit codes | 0: complete bounded eligibility with no findings; 1: complete with findings; 2: invalid invocation/manifest, unavailable/unsupported connection or incomplete prerequisites. Every result has rollout_ready=false and ownership_mapping/platform_grants/backup_restore/mysql_rehearsal unverified |
| Exclusions | No DB/schema/file mutation, platform/membership activation, owner inference, secrets export, real DB apply/backfill, route/UI/registration or auth-policy change |

Acceptance criteria:

- Strictly decode bounded JSON without ambiguous object keys/types/duplicate mappings; keep value-object contents private from accidental JSON serialization.
- Reject missing/unknown connections before file/DB work; reject malformed or inaccessible files without disclosing path/content or logging raw exceptions. No DB lookup for invalid manifests.
- Check selected accounts/events and complete unowned-event coverage through query/schema reads, with safe incomplete behavior for malformed schema/keys and sanitized unavailable failures.
- Report only allowlisted aggregates; no implied legitimate-owner approval, privilege grant, restore proof, atomic snapshot or rollout readiness. Read relations/default-event fallbacks and model saves are forbidden.
- Prove parser edge cases and resource limits, database eligibility/coverage, already-owned/missing/inactive/unsupported accounts, CLI exits/privacy, explicit connection isolation, repeated output and full DB/file preservation. Exercise IDs beyond one DB batch.
- Run focused/full isolated checks, formatting/syntax, command/routes/whitespace and baseline preservation; update the task's dated completion record.

## IMPLEMENTED — P3-003 evidence

Full isolated regression passed **529 tests / 5,151 assertions** (81.998851s). Focused ownership/throttle checks passed **127 tests / 1,005 assertions** (17.760108s). Nine task PHP files passed Pint and syntax checks. Command discovery, explicit missing-option/empty-schema exit 2 and all 97 HTTP routes passed; source whitespace and baseline preservation checks passed.

Strict private manifest validation, selected-account eligibility and full unowned-event coverage are verified through 119 new cases. Independent reviews found no blockers. A slow-host full run exposed real-clock assumptions in two existing throttle tests; eight burst tests now freeze time and two also verify expiry after 61 seconds. No runtime authentication code changed. [Operator guide](../OWNERSHIP_MAPPING_PREFLIGHT.md), [phase report](../PHASE_03_REPORT.md).

## PLANNED — backfill and enforcement contract

A later write task must use a reviewed real manifest, verified database/file restore, actual-engine rehearsal and a durable private mapping ledger before claiming resumability/idempotence. It must preserve immutable event/guest/QR/manual/admission/offline values, verify current eligibility under transactions/locks, reconcile existing mappings deliberately and retain failed/conflicting outcomes for review. Do not use workspace names or global admin roles as ownership keys. Platform grants require their own explicit authorization/enforcement plan. This read-only task does not implement or approve that writer.

## P3-004 — private plan identity and dormant resolution ledger

Status: COMPLETED in source/tests (2026-10-18). Depends on the P3-003 source/test gate; no actual ownership, restore or engine gate is treated as satisfied.

Objective: prepare deterministic private identity for validated proposals and empty durable reference-resolution storage for a future separately reviewed writer.

| Field | Contract |
|---|---|
| Files | OwnershipPlanIdentity value object/unit tests; additive ledger migration/isolated integration tests; bounded inventory catalog/expected-count tests and evidence docs |
| Identity API | fromManifest(OwnershipManifest):self, digest():string; private readonly digest, no string or JSON serialization; no changes to public report/CLI formats |
| Canonical v1 | Fixed property order schema_version, workspaces, platform_admin_user_ids; groups sorted by reference byte order; each group's reference/name/owner_user_ids/event_ids fixed, integer ID sets sorted with integer comparison; preserve exact name text; domain-separated SHA-256 using exact PHP JSON_UNESCAPED_SLASHES / JSON_UNESCAPED_UNICODE / JSON_THROW_ON_ERROR flags (default U+2028/U+2029 escaping retained) |
| Domain | Exact prefix kadify:legacy-ownership-plan:v1 followed by a NUL byte; canonical schema_version=1. Internal lowercase 64-character hash; identity is not signature, approval or secrecy proof |
| legacy_ownership_plans | Numeric id, required unsigned tiny schema_version (no inferred approval/status), required unique char64 manifest_hash, timestamps; no manifest copy or automatic rows |
| legacy_workspace_mappings | Numeric id, required ownership_plan_id restrictive FK, required manifest_reference string64, required workspace_id restrictive FK and globally unique target, timestamps; unique(plan,reference), explicit short index/FK names |
| Migration | New 2026_10_18_010000_add_legacy_ownership_ledger.php; explicit resolved connection; supported SQLite/MySQL/MariaDB only; refuse active SQLite transaction, missing workspace parent id or unsafe parent key before DDL; require declared single-column workspaces.id primary key and observed nonnull IDs; reject either existing ledger table/view before creation |
| Reversal | Require both complete known ledger tables before DDL; refuse if either has any row. Empty down drops mappings before plans. Keep populated ledger during code rollback; non-atomic DDL failures require review/forward repair |
| Inventory | Expand fixed count/required-column catalog from29 to31; ledger counts/schema only, never hashes/references/rows/provenance/approval. Ownership proposal preflight remains its existing selected four-table scope |
| Compatibility | No model hooks, automatic plan/mapping creation, writer/apply/approval switch, HTTP/UI/auth/permission changes, dependency/frontend changes or real database migration |

Acceptance criteria:

- Equal validated semantics produce equal private identity despite JSON formatting/property/group/ID-list ordering. Any actual reference/name/owner/event/platform mapping change changes identity; exact Unicode/whitespace names and PHP integer boundaries remain distinct.
- Keep digest private from ordinary JSON serialization, CLI reports and logs; identity calculation performs no database/file writes and grants no authority.
- Install only empty additive ledger structures on isolated fixtures; preserve all pre-existing rows/schema and legacy event/guest/QR/manual/admission/offline identifiers.
- Enforce duplicate plan hash, same-plan reference and target workspace uniqueness and restrictive parent links; prove explicit refusal before DDL for existing/partial/conflicting/unsupported/transaction cases and populated down.
- Prove empty reversal/reapply, named connection isolation, zero automatic records/grants, private count-only inventory and unchanged route/auth boundaries. Test migration chronology correctly when cloning the later full schema for earlier migration tests.
- Run focused/full isolated regression, formatting/syntax, CLI/routes/whitespace and baseline conservation; update dated objective/acceptance/evidence records.

Limits: stable plan/reference storage alone does not provide event/member/platform outcome journaling, crash recovery, transactional reconciliation, resumability or idempotent application. Changed manifests are separate proposals requiring explicit reconciliation, never automatic retries. A later writer still requires real inventory, legitimate owner/platform authorization, verified database/file restore and actual-engine rehearsal. Do not persist private inputs or run the new migration on a configured database in this task.

## IMPLEMENTED — P3-004 evidence

Full isolated regression passed **581 tests / 5,399 assertions** (156.775272s). Focused ledger/workspace/inventory checks passed **106 tests / 1,380 assertions** (78.078061s). Nine task PHP files passed Pint and syntax checks. All 97 HTTP routes and the expanded 31-table empty-schema CLI smoke passed with required exit 2; source whitespace and baseline preservation checks passed.

Twenty identity cases pin canonical bytes and private deterministic semantics; thirty migration cases cover conservation, constraints and explicit pre-DDL guards; inventory covers counts/privacy only. Historical P3-002 tests reverse the later empty ledger on their independent clones before exercising the earlier migration. No writer or real rollout is introduced. [Ledger guide](../OWNERSHIP_LEDGER.md), [phase report](../PHASE_03_REPORT.md).

## P3-005 — dormant transactional workspace backfill preparation

Status: COMPLETED in source/tests (2026-10-18). Depends on the P3-004 source/test gate. Actual owners/platform approval, inventory, restored backups, maintenance fencing and real-engine rehearsal remain unsatisfied.

Objective: implement an unreferenced backend service and isolated fixtures that atomically prepare one workspace's inactive owners, event ownership and ledger resolution, and verify exact state before same-proposal retry.

| Field | Contract |
|---|---|
| Files/API | New LegacyWorkspaceBackfill::apply(Connection, OwnershipManifest):array; OwnershipBackfillSchema::assertReady(Connection):void; fixed safe OwnershipBackfillException::because(reason), reason():string; focused schema/backfill tests and evidence docs |
| Reachability | No CLI, HTTP, job, listener, config activation or caller in application runtime. Only isolated tests invoke it; do not connect to or write any configured database in this task |
| Early refusal | Reject any nonempty platform_admin_user_ids before DB writes; reject ambient transactions on every engine, unsupported drivers, missing/unsafe schema/keys/unique/FK prerequisites or disabled FK/nontransactional engines |
| Schema | Six tables users/events/workspaces/workspace_users/legacy_ownership_plans/legacy_workspace_mappings with required columns, compatible integer single-column nonnull id primary keys and ID-allocation metadata for the four inserted tables; generated identities also require uncoerced positive PHP-range validation; exact unique plan hash, plan/reference, mapping workspace and membership workspace/user; restrictive known FKs. SQLite FK enforcement on; MySQL/MariaDB relevant tables InnoDB with enforced session FK checks |
| Validation before writes | Explicit owners exist, strict active=1 and exact admin/scanner role; selected events exist and are unowned or assigned to exactly this digest/reference's verified workspace. Every currently unowned event including default/archived/cancelled is covered. Reject unknown mappings, attempts to reuse owned targets under changed proposals, or drift before creating a plan row |
| Identity/resolution | Canonical private OwnershipPlanIdentity; exact hash/version plan resolution, never workspace names or global roles. References sorted bytewise; bounded row reads, selected ID batches <=500; no silent adoption/repair/reconciliation |
| Commit unit | Reject ambient transaction so each group's transaction is real. Plan-row lock, matching mapping, selected owners/events ascending, then target/membership reads. Revalidate under lock. New workspace exact name + exact role=owner/is_active=false memberships + listed-null event ownership + mapping insertion commit together. Conditional null updates require exact affected-row counts; any failure rolls back that group |
| Retry | Existing exact proposal mapping must match exact workspace name, exact inactive owner membership set/roles and exact event ownership/set; verify without any rewrite/timestamp change. Altered/active/extra state is conflict requiring explicit reconciliation. Earlier committed groups remain after later failures and are verifiable on retry |
| Conservation | Query builder only, no Eloquent/defaultEvent/hooks. Change only listed events.workspace_id, insert new workspace/inactive memberships/plan/mappings; preserve every existing event timestamp, user/scanner role/assignment/credential/platform value, guest/QR/manual/admission/offline record |
| Errors/output | Fixed safe reason/message, no original exception/previous context/logs/private output. Reasons: platform_proposal_unsupported, invalid_prerequisites, eligibility_changed, coverage_conflict, ledger_conflict, database_unavailable. Successful aggregate schema_version=1/status=complete; workspaces_created/workspaces_verified, memberships_created/events_assigned; platform_grants=0; rollout_ready=false and four actual gates unverified |
| Concurrency limit | Initial/current/final unowned coverage rechecks are observations, not global fencing. Listed-row/plan locks and conditional writes cannot stop unrelated new events. Actual execution requires a verified maintenance/cooperating-writer fence; SQLite does not prove row locks or MySQL concurrency. Final conflict may leave prior groups deliberately retained; recover through exact ledger verification |

Acceptance criteria:

- Prove first apply and equivalent-order retry, exact inactive owner/event sets and preserved timestamps/legacy identities; no automatic grant, activation, scanner assignment or reachable runtime writer.
- Prove complete pre-write rejection of platform intent, invalid schema/engine/transactions/keys/constraints, unavailable/inactive/unsupported owners, missing/uncovered/already-owned events, changed manifests and drift.
- Inject failures at workspace/member/event/mapping writes and prove full current-group rollback; retain earlier committed groups after later failure and retry safely. Simulated interleaving/coverage changes must be labelled and fail safely without claiming real concurrent-engine proof.
- Verify exact private ledger identity, selected IDs across batch boundaries, sanitized original query exceptions without logs/previous context, and named connection/default preservation.
- Run focused/full isolated regression, formatting/syntax, unchanged command/routes, whitespace/baseline checks; update dated objective/acceptance/evidence.

Limits: dormant source preparation is not approval or a deployment/backfill. No real manifest, actual database write, public/customer registration, platform grant or authority enforcement is authorized by passing fixtures. A source service cannot verify human ownership, restored backups or fencing. It is not a complete audit journal; failure may leave a private plan row and earlier committed groups that must be reviewed and resumed only with the same approved proposal.

## IMPLEMENTED — P3-005 evidence

Full isolated regression passed **656 tests / 6,689 assertions** (101.487276s). Focused backfill/schema checks passed **75 tests / 1,290 assertions** (10.530528s). Five new PHP files passed Pint and syntax checks. All 97 HTTP routes, unchanged read-only command options and the 31-table empty-schema CLI smoke passed; source whitespace, zero application callers and baseline preservation checks passed.

Forty-two backfill and thirty-three schema cases prove isolated commit/retry/conservation behavior. Review tightened exact mapping postconditions, prefix/expression index checks, SQLite allocation and raw generated-ID range validation. A fully committed exact retry writes no records; partial retries can create the remaining groups. A distinct proposal cannot adopt existing owned targets as a retry. No configured DB, runtime caller, public writer or platform grant is involved. [Preparation guide](../WORKSPACE_BACKFILL_PREPARATION.md), [phase report](../PHASE_03_REPORT.md).

## P3-006 — disposable actual-engine rehearsal

Status: COMPLETED for the bounded synthetic rehearsal (2026-10-18). Depends on P3-005 source/test completion. Real customer inventory, legitimate owners/platform approval, customer database/file restore and maintenance fencing remain open.

Objective: exercise repository migrations and dormant backfill behavior on a separate real local MySQL engine using synthetic data, then stop the owned server and preserve all existing environments.

| Field | Contract |
|---|---|
| Files | Temporary isolated server/profile/runner/evidence under the task TEMP directory; dated project/phase/operator evidence docs. Any discovered source repair requires a focused regression and full suite before completion |
| Distribution | Reuse installed MySQL8.4.11 Win64 Community binary read-only; verified Oracle America Authenticode status Valid and version; record local archive SHA-256 A492371D687D2BAB088B0062581144A0044B8964BAEFDF4FAA579292B423D25C. No install/download/update or changes to shared binaries |
| Isolation | --no-defaults, fresh task-owned TEMP data/log/tmp/PID paths, separate available loopback-only TCP port, MySQL X disabled; no Windows service, XAMPP/default MySQL/configuration changes or configured app DB connection |
| Credentials | Generated private admin/application passwords in task TEMP profile/client files only; no command-line secrets, source control, stdout or raw exception output. Application account scoped to generated rehearsal/restore schemas; no real user data |
| Laravel | Process-local testing connection/default/storage/mail/cache/session overrides point only at the owned server/synthetic schema before boot; never edit .env, app key, cached config, manifests or public build. No seeding/destructive command against any existing database |
| Migration rehearsal | Apply repository history to fresh synthetic schema; exercise empty ledger/foundation reverse/reapply and populated legacy upgrade conservation; inspect actual MySQL key/FK/engine metadata and read-only preflights |
| Backfill | Real InnoDB service first/partial/retry behavior, inactive owners, strict Unicode and unchanged legacy fields; all four public readiness gates remain unverified in tool output. Verify safe constraint failures and real raw unsigned generated-ID overflow refuses inside allocation transaction |
| Lock/restore | At least two independent real connections demonstrate selected-row lock exclusion with bounded session timeout; synthetic SQL dump/restore and restart preservation where supported. These are selected fixture checks, not comprehensive race/fencing or customer-backup proof |
| Lifecycle | Capture owned PID/port, start/stop only this exact process, prove stop and credential/config preservation on restart; unavailable connection fails safely. Remove only task-created credential plaintext after shutdown; no recursive cleanup of external directories |

Acceptance criteria:

- Prove actual server identity, loopback isolation, private scoped account and InnoDB/strict/UTF-8/UTC behavior without changing existing environments.
- Run actual repository DDL and selected metadata paths, preserve populated legacy identifiers/values through expansion, and verify preflight/backfill retry/rollback/private outcomes on synthetic records only.
- Exercise real unsigned generated-ID range refusal, selected independent-connection row locks, constraint rejection and synthetic restart/restore conservation; state the practical limits of each result.
- Stop the owned process, verify safe unavailable behavior and preservation of application/config/build/dependencies/other services; save dated objective/acceptance/evidence and remaining real rollout gates.

Limits: this verifies only the recorded MySQL version and synthetic fixture. It does not certify MariaDB, another hosting build/collation, full production row-lock races, unrelated-writer fencing, actual owner approval, customer backup/file restore, Android/offline interoperability or a reachable production writer. Phase3 remains IN PROGRESS after these bounded checks.

## IMPLEMENTED — P3-006 evidence

Full isolated regression passed **656 tests / 6,689 assertions** (165.260393s), with zero errors/failures/skips. The repaired migration passed Pint/syntax. All 97 routes and the 31-table empty-schema read-only CLI smoke passed; baseline/link/whitespace checks passed.

Actual MySQL 8.4.11 Community (Oracle-signed Win64 distribution) applied all 27 repository migrations on a fresh synthetic schema. The fixture passed 108 checks, conserved 39 legacy rows, rejected seven unique/FK violations and verified eight historical offline-index transitions. It proved current-group rollback/earlier-group retention, partial retry and zero-write completed retry, independent-connection row-lock timeout/release, exact-name drift rejection under a case-insensitive SQL collation, and real unsigned plan/workspace IDs above PHP_INT_MAX refusing without accidental target adoption.

The same server identity and every fixture row survived restart. A scoped-account mysqldump/mysql restore into the second fresh owned schema matched all rows across 31 tables and passed the backfill schema guard. After clean shutdown, the connection failed safely with driver code 2002/exit 2; port 43484 was free and task-created plaintext profile/client/bootstrap credentials were removed.

The first actual migration run failed with MySQL 1553 at 2026_07_23_190000: dropping the last old unique index removed the index supporting os_log_session_fk before its replacement existed. The narrow repair creates the four replacement indexes before dropping their predecessors in both directions; final names/columns/uniqueness are unchanged. Fresh history and empty down/up now pass with FK checks enabled. It does not repair an already partially applied live migration or certify populated rollback after duplicate conflict records exist.

This is bounded synthetic MySQL 8.4.11 evidence. Configured/customer database identity/schema/inventory, representative customer database and private-file restoration, legitimate owners/platform approval, maintenance fencing, comprehensive races/deadlocks and MariaDB remain unverified. No configured database, XAMPP, other project data/service, runtime writer, grant, frontend/dependency change or deployment occurred. Phase 3 remains IN PROGRESS. [Guide](../MYSQL_REHEARSAL.md).

## P3-007 — read-only exact backfill progress inspection

Status: COMPLETED in source/tests (2026-10-19). Depends on P3-006 bounded rehearsal and P3-005 source/test gate. Real owner/platform approval, customer restore and maintenance fencing remain open.

Objective: inspect a private exact proposal against unstarted/partial/completed ledger state without invoking any writer or making approval claims.

| Field | Contract |
|---|---|
| Scope/files | Shared OwnershipBackfillState read/validation helper extracted narrowly from dormant writer; LegacyWorkspaceBackfillInspection; tenancy:backfill-inspect command; bounded local OwnershipManifestFile reader shared with existing ownership-preflight; focused service/CLI regressions and evidence docs |
| Dependencies | Existing strict OwnershipManifest/identity, OwnershipBackfillSchema, dormant backfill exact state rules; preserve writer public apply behavior and old preflight contract |
| Database/API/UI | Explicit configured connection and private local manifest only; no schema/data writes, transactions, row locks, default connection changes, HTTP/API/UI/dependency/Android changes or reachable writer |
| Exact checks | Same private digest/version/reference resolution, current eligible owners, exact byte names, exact inactive owner/member sets and event ownership/sets; complete currently unowned-event coverage. No name adoption, changed-proposal reconciliation or repair |
| Output | Successful fixed aggregate schema_version1/status complete/mode read_only; progress unstarted/partial/exact, proposal counts and observed plan-present/verified/remaining groups and assigned/remaining proposed events; no IDs/names/references/hashes/paths/query text. Empty proposal with no unowned events is exact with no required plan |
| Failures | Strict existing manifest/local-file bounds; fixed safe exception reasons without original/previous context/logs. CLI exit0 for successful observation,1 for semantic proposal/state conflict,2 for invalid invocation/schema/unavailable engine. No apply/approve/repair switch |
| Security/limits | Platform grant intent refused explicitly. All four real gates stay unverified, rollout_ready=false. Multi-query observations are not a consistent snapshot, future retry guarantee, authorization, backup proof or writer fence |

Acceptance criteria:

- Verify unstarted, retained empty plan, partial and exact states, equivalent ordering, empty proposal and aggregate privacy; never call apply or begin/commit/rollback/lock/write.
- Reject changed/unknown/extra/missing/active/altered committed state, current owner/event/coverage conflicts, unsafe schema/enforcement/ambient transactions and platform intent with safe output.
- Preserve every schema/row/timestamp/default connection/manifest/config/build/dependency value during inspection, including exceptional paths; test private SQL failures and selected IDs beyond500.
- Reuse exact validation in writer and inspector without changing commit boundaries/raw-ID/write/retry semantics; run existing focused writer/schema/preflight tests plus full regression, syntax/format/routes/CLI and baseline/link checks.
- Document exact observation limits and successful dated objective/acceptance/evidence after completion; keep configured/customer database and writer untouched.

## IMPLEMENTED — P3-007 evidence

Full isolated regression passed **717 tests / 10,483 assertions** (111.554235s), with zero errors/failures/skips. Focused inspection/writer/schema/proposal checks passed **169 tests / 5,677 assertions** (12.416401s). Eight scoped PHP files passed Pint/syntax; all 97 routes, new command help/missing-connection exit2 and the 31-table empty-schema CLI smoke passed. Baseline/whitespace/local-link checks passed.

The new tenancy:backfill-inspect command observes an explicit private proposal against unstarted, retained empty-plan, partial and exact ledger state. Shared exact state validation preserves dormant writer checks/locking/commit/raw-ID semantics; the bounded local manifest reader is shared with ownership-preflight without changing that command. Inspection has no writer dependency, apply call, transaction, row lock, mutation, grant, activation or approval option. Added61 cases/3,794 assertions across exact progress and CLI/file-read coverage; full schema/all-row conservation and transaction/query observation remain unchanged. Compared327 baseline paths; only two existing PHP files were refactored and six PHP files added. Independent review found no blocker. Results are multi-query observations, not a consistent snapshot, retry guarantee, legitimate-owner approval, backup evidence or maintenance fence. All four external gates stay unverified and rollout_ready=false. No configured/customer database, actual owner/platform grant, runtime writer, HTTP/API/UI/dependency/Android change or deployment was involved. Phase 3 remains IN PROGRESS. [Guide](../BACKFILL_PROGRESS_INSPECTION.md).

## P3-008 — dormant fresh authority-context resolution

Status: COMPLETED in source/tests (2026-10-19). Depends on P3-007 source/test gate. Real owner/platform grants, membership activation and Phase4 action policies remain separate.

Objective: resolve current account eligibility, explicit platform capability and exact active membership context independently, without wiring runtime enforcement or inventing action permissions.

| Field | Contract |
|---|---|
| Files/API | New AuthorityContextResolver::resolve(Connection,mixed userId,mixed workspaceId=null):AuthorityContext; private immutable AuthorityContext with isAccountEligible(),hasPlatformCapability(),workspaceRole(); fixed AuthorityContextException reasons; focused tests and affected docs |
| Reachability | No application runtime caller, route/CLI/middleware/policy/model/session hook, grant/activation workflow, public/customer exposure or existing authorization change |
| Identity inputs | Explicit connection, positive PHP integer user ID and optional positive PHP integer workspace ID only. Reject floats/numeric strings/bools/zero/negative/overflow before schema/query reads; never infer context from authenticated/cached models, sessions, ledger, names or legacy event assignment |
| Read prerequisites | Supported SQLite/MySQL/MariaDB, known users/workspaces/workspace_users tables and required columns, declared single integer id primary keys. Read-only guard does not require ownership ledger/events, allocation metadata or new schema. No full-table row scan; bound selected identity/parent/membership reads to2 for duplicate refusal |
| Fresh facts | Read current rows from the explicit connection write PDO on every invocation; never cache user/member/flag state. Account eligible only canonical active=true and exact current supported admin/scanner role (compatibility boundary). Platform capability only eligible account plus strict explicit flag=true; legacy admin alone cannot grant it |
| Membership | Only requested persisted workspace and exactly one current membership matching exact user/workspace, canonical active=true and exact owner/manager role. Missing/inactive/deleted/malformed/duplicate rows yield no membership context; platform capability never substitutes for membership. Scanner-owner remains separate from global/platform authority |
| Context/output | Immutable private booleans and nullable owner/manager role only; JSON{} and no implicit string conversion, no IDs/PII/query data. A context is observed facts, not an unforgeable authorization token; no action allows/check-permission API or invented Manager grants |
| Errors/conservation | Fixed safe invalid_identifiers/invalid_prerequisites/database_unavailable reasons and message; discard original/previous/log context. No writes, transactions, locks, default connection changes, grants, schema/frontend/dependency/Android edits. Preserve any caller transaction level; visibility follows that connection transaction/isolation/replica configuration, so no global revocation/fence guarantee |

Acceptance criteria:

- Prove admin-without-flag has no platform capability, explicit capability stays independent of workspace role, scanner-owner separation and Manager recognition without action grants; verify no auto context selection or role/name/ledger inference.
- Prove repeated reads observe account/flag/membership/role revocation and changed workspace parents on the explicit primary connection outside an ambient snapshot; no cached Eloquent/auth relation grants remain.
- Fail closed on missing, inactive, deleted, unknown, cross-workspace, malformed or duplicate membership/parent/account state; reject unsafe IDs/schema/driver and sanitize unavailable private SQL failures.
- Verify private immutable context invariants, named/default connection isolation, bounded selected queries, unchanged full database/schema/timestamps/config, zero writes/transactions/locks and caller-transaction preservation.
- Run focused/full regression, formatting/syntax, unchanged HTTP/CLI smoke and baseline/link checks; update completed dated evidence and keep real grants/activation/customer rollout gates open.

## IMPLEMENTED — P3-008 evidence

Full isolated regression passed **795 tests / 12,771 assertions** (127.333681s), with zero errors/failures/skips. Focused authority/authentication/workspace checks passed **128 tests / 2,549 assertions** (9.153834s). Five new PHP files passed Pint/syntax; all 97 routes, unchanged read-only CLI options/exits and 31-table empty-schema inventory smoke passed. Baseline/whitespace/local-link checks passed.

AuthorityContextResolver performs fresh primary reads on an explicit connection and strict positive PHP integer user/optional workspace IDs. It independently observes canonical active-account eligibility, explicit platform capability and the exact requested active owner/manager membership. It has no runtime caller, action-permission API, default selection, grant, activation, policy wiring, write, transaction or lock. Added78 cases/2,288 assertions on independent minimal schemas, including stale/empty read PDOs and full conservation. A null-dispatcher cleanup issue was fixed in tests only. Review then reproduced an auxiliary SQLite replica metadata read with a throwing-replica trap; the new reader now processes required column metadata on the primary only. All334 existing baseline paths are preserved outside docs. Observed facts are not authorization or an unforgeable token. Current admin/scanner account-role compatibility is preserved; Manager action permissions, platform support bypass, ownership transfer/last-owner rules and runtime tenant enforcement remain undefined. Visibility follows the explicit connection and caller transaction/isolation; fresh queries do not fence concurrent changes or invalidate an already retained context. Real ownership/platform approval, customer restore, membership activation, maintenance fencing and Phase4 action/resource policies remain open. Phase3 remains IN PROGRESS. [Guide](../AUTHORITY_CONTEXT.md).

## P3-009 — disposable MySQL multi-process backfill/disconnect rehearsal

Status: COMPLETED for selected synthetic cases (2026-10-19). Depends on P3-008 passing source/test gate and P3-006 actual-engine isolation pattern. Real owner/platform authorization, customer restore and maintenance fencing remain open.

Objective: exercise selected actual service contention and worker-disconnect recovery with separate PHP processes and deterministic barriers on a new task-owned MySQL instance.

| Field | Contract |
|---|---|
| Scope/files | TEMP-only new private server/profile/synthetic fixtures/coordinator/workers/barriers/safe aggregate evidence; affected dated docs. No application/schema/route/CLI change planned; a discovered source fix needs its own regression/full-suite verification |
| Isolation | Reuse verified Oracle-signed MySQL8.4.11 Win64 distribution read-only; fresh task-owned private TEMP data/log/tmp/PID and generated case schemas, loopback127.0.0.1:43489, MySQLX disabled, no Windows service/XAMPP/configured database/other project data change. Verify UUID/datadir/port/bind/version before fixture writes |
| Credentials/processes | Generated private task admin/application passwords never source/argv/stdout; application grant only explicit generated schemas. Own PHP worker arguments contain only task/case/mode identifiers. Terminate only a recorded task-created PHP PID after executable/script/task identity verification; preserve unrelated processes |
| Same proposal | Existing empty plan: workerA holds a real service plan-row lock at a bounded barrier; independent workerB demonstrably waits; releaseA, create once and verify without duplicate DML. Fresh missing-plan race: both service transactions pass the missing-plan lock read; allow sanitized deadlock/duplicate/unavailable outcome and prove safe exact retry with no duplicate committed target |
| Competing proposal | Two distinct retained one-group proposals cover the same selected events. Hold winner current-group transaction, observe loser contention, then release; no reassignment/name adoption or duplicate committed target. Loser and its retry refuse safely; an empty losing plan may remain |
| Disconnect/retry | Two-group worker commits group1, pauses during group2 DML before commit, then only that verified owned PHP process is terminated. InnoDB must roll back all unfinished group2 records, preserve group1; read-only inspector observes partial, fresh worker resumes remaining group and completed retry performs no DML |
| Evidence | Separate real connections/processes, deterministic TEMP barriers, bounded lock/process timeouts and actual owned-server lock-wait metadata (private admin only). Compare committed records/legacy identifiers/fields, not AUTO_INCREMENT gaps. Private inputs remain private; outputs allowlisted statuses/reasons/aggregates only |
| Lifecycle/tests | Outer finally shuts down only owned server and cleans any owned workers, even when coordinator fails. Prove owned port/process stopped and remove task-created plaintext credentials after successful checks. Reuse current795-test/12,771-assertion source gate if application files remain unchanged; lint harness, route/CLI/preservation/link checks; rerun focused/full if source repair occurs |

Acceptance criteria:

- Verify task-owned server/schema/process identity, generated scoped credentials and actual MySQL session strict/UTF8/UTC/FK settings without using existing configured DBs or services.
- Prove selected existing-plan contention, missing-plan race and overlapping one-group proposal behavior through separate service processes with bounded deterministic barriers; allow safe sanitized failure rather than imposing new retry semantics.
- Prove real worker-disconnect rollback/current-group conservation, earlier-group persistence, partial/exact read-only inspection, remaining-group resume and zero-DML completed retry.
- Preserve all pre-existing application/config/build/dependency files and all synthetic legacy fields except permitted events.workspace_id; retain runtime writer unreachability and all external readiness gates.
- Stop exact owned processes/port, clear generated plaintext credentials, save safe aggregate evidence and dated objective/acceptance/limits in the project plan.

Limits: selected bounded fixture interleavings are not comprehensive deadlock/crash/durability/host/engine certification or a maintenance fence. Competing multi-group proposals may retain different committed groups, so do not claim a single complete winning plan in general. No new global mutex/automatic retry, actual customer writer, platform grant, membership activation, production backup/file restore or rollout is authorized by passing this rehearsal. Phase3 remains IN PROGRESS.

## IMPLEMENTED — P3-009 evidence

All four bounded actual MySQL8.4.11 cases passed with11 separate PHP workers across four fresh synthetic schemas, each applying27 repository migrations/31 tables. Actual owned-thread lock waits, commit/retry outcomes and full legacy-row conservation were verified. Five TEMP PHP files passed syntax checks and three PowerShell scripts parsed successfully. All97 routes, unchanged read-only CLI options/exits and31-table empty-schema inventory smoke passed; baseline/links passed.

Existing-plan workers produced one created workspace and a waiting verifier with zero DML. The fresh missing-plan race produced one successful creator and one sanitized engine conflict; exact retry wrote nothing and no duplicate target committed. Competing single-group proposals produced one exact winner and an observed MySQL1213 deadlock sanitized as database_unavailable; the losing retry refused ledger_conflict with zero DML, reassignment or name adoption, retaining one empty losing plan. Terminating only the verified owned PHP worker during group2 caused InnoDB to discard the entire unfinished group while preserving group1; inspector partial, remaining-group resume and zero-DML exact retry all passed.

The separate loopback127.0.0.1:43489 server used a fresh private TEMP data/log/tmp/PID directory, --no-defaults, MySQLX disabled and no Windows service. Oracle signature/version and unchanged distribution archive hash were verified. Generated application credentials were scoped to four exact fixture schemas; unscoped CREATE DATABASE was rejected. UUID/datadir/bind/port and strict SQL/utf8mb4/UTC/FK settings were checked before fixture writes. All owned workers and the server stopped cleanly; port43489 was free, unavailable connection returned safe2002 and all four generated plaintext credential files were removed.

No application code, migrations or tests changed in P3-009. The current P3-008 full result **795 tests / 12,771 assertions** (127.333681s, zero errors/failures/skips) was reused only after all340 captured pre-existing source/config/build/dependency paths were checked unchanged outside task docs. No new full-suite run is claimed for this TEMP-only rehearsal. This verifies selected service interleavings and a worker disconnect on the recorded synthetic MySQL build, not complete crash/durability/deadlock/race coverage, another hosting engine, maintenance fencing, customer backup/file restoration or legitimate owner/platform approval. Competing multi-group proposals may retain different committed groups; no single complete winning proposal is guaranteed in general. No automatic retry/global mutex, runtime writer, platform grant, membership activation, XAMPP/configured database change or deployment occurred. Phase3 remains IN PROGRESS. [Guide](../MYSQL_CONCURRENCY_REHEARSAL.md).

## P3-010 — existing admission actual-engine conservation baseline

Status: COMPLETED for selected synthetic cases (2026-10-19). Depends on P3-009 isolated worker/server verification and current P2 authentication/scanner source gate. Cross-cutting legacy behavior characterization for migration conservation; it does not start Phase5/13 service extraction or customer policies.

Objective: verify selected simultaneous last-entry admission and unfinished-transaction recovery on current scanner routes using synthetic actual MySQL data.

| Field | Contract |
|---|---|
| Scope | TEMP-only server/profile/fixtures/coordinator/workers and dated evidence docs; no application/controller/model/route/API/schema/dependency/UI/Android change planned. A discovered repair requires concrete regression and full suite before completion |
| Isolation | Fresh task-owned MySQL8.4.11 private data/log/tmp/PID, loopback127.0.0.1:43490, generated exact fixture schemas/scoped credentials; read-only reuse of verified distribution. Preserve XAMPP/configured DBs/other project data and app .env/app key/build/dependencies |
| Requests | Separate PHP workers invoke the unchanged Laravel HTTP kernel with synthetic persisted scanner identities/test sessions and assigned active event. Use existing QR JSON/manual browser POST routes. Test-mode CSRF/session setup is not real browser/password/device/HTTPS deployment proof; no external HTTP server or messages |
| Last-entry cases | QR+QR, manual+manual and both QR/manual orderings, targeting one remaining admission. Pause holder after authoritative guest counter UPDATE before history completion; observe actual independent-worker lock wait, verify uncommitted changes invisible, release. Exactly one success; no counter/capacity overshoot or second admission. Verify expected QR failure audits/manual failure behavior without changing response shapes |
| Disconnect | Terminate only recorded identity-verified task PHP worker while counter update is uncommitted; require exact prior counter/status/history conservation, then fresh worker succeeds once and exhausts allowance |
| Invariants | Preserve target guest ID/event/name/phone/manual code/QR token and unrelated legacy/offline/user/workspace rows. Only documented guest used_entries/status/updated_at and new Admission/Checkin/ScanLog rows may change. Successful quantities sum to counter increase; rejected attempts add no admissions/entries. Allow allocator gaps and preserve current event-lock serialization |
| Privacy/lifecycle | Private raw request/response values never printed; report allowlisted HTTP/business status/counts only. Generated passwords absent from argv/source/stdout. Bounded barriers/timeouts, owned-process identity checks, outer finally cleanup, safe unavailable behavior and plaintext credential removal after shutdown |
| Tests | Actual unchanged scanner route/controller/lock/counter/history paths on full synthetic repository schema; harness lint, HTTP/CLI smoke and baseline/link checks. Reuse current795-test/12,771-assertion source gate only after unchanged-source proof; rerun focused/full for any source repair |

Acceptance criteria:

- Prove real owned-server/session identity, exact scoped fixture account and independent authenticated test workers, with no configured environment/service change.
- Prove four controlled last-entry pairs, actual owned-thread lock waits and precommit invisibility; preserve response compatibility and exact quantity/counter/history relationships with no overshoot.
- Prove worker-disconnect rollback of counter/status/history and subsequent single success, using only the verified owned PHP PID.
- Compare committed rows/immutable guest/QR/manual/offline/account identifiers and expected audit differences; preserve all other captured source/config/build/dependency files.
- Stop exact owned processes/port, remove generated plaintext credentials, record dated objective/acceptance/evidence and narrow R11 implications without claiming general concurrency, idempotency, production/device or tenant readiness.

Limits: selected legacy-route fixture cases do not certify all interleavings, throughput, hosted MySQL/MariaDB, QR camera UX, offline interoperability or customer tenant isolation. Existing lack of online request idempotency remains; a lost successful response can consume another allowed entry. No action-policy/public/customer rollout gate is bypassed. Phase3 remains IN PROGRESS.

## IMPLEMENTED — P3-010 evidence

Five selected cases passed on a separate MySQL 8.4.11 server using ten independent PHP workers and five fresh synthetic databases, each with 27 repository migrations and 31 tables. Four last-entry pairs (QR/QR, manual/manual, QR/manual and manual/QR) showed actual owned-thread lock waits and precommit invisibility. Each pair committed exactly one admission, rejected the other attempt and ended at used_entries=3, allowed_entries=3, with no overshoot.

The guest counter increase, new Admission quantity and successful Checkin entries all equalled one. Prior historical rows were preserved exactly. QR rejection added a zero-entry Checkin and a verify ScanLog; manual rejection added neither, preserving existing response/audit differences. Every other table/record and immutable guest, manual-code, QR, account and offline-provenance field matched the fixture snapshot.

Terminating only the identity-verified owned PHP holder after its uncommitted guest-counter UPDATE caused MySQL to roll back the counter/status change and leave history unchanged. Its connection thread disappeared, the complete prior committed snapshot returned, and a fresh worker then admitted exactly once.

No application code, migration or test changed. All 341 captured pre-existing paths were compared; changes were limited to task documentation. The unchanged-source P3-008 gate of **795 tests / 12,771 assertions** (127.333681s, no errors/failures/skips) was reused; no new full-suite run is claimed. Five TEMP PHP files passed syntax checks, four PowerShell scripts parsed, all 97 routes registered, and existing read-only command options/exits and 31-table empty-schema inventory smoke passed.

The private server bound only to 127.0.0.1:43490 with MySQLX disabled and no Windows service. Its UUID, data directory, version and session strict SQL/utf8mb4/UTC/FK settings were checked before fixture writes. Oracle Authenticode signature and the existing distribution archive SHA-256 were verified. Generated application credentials had grants only on the five exact fixture databases; unscoped CREATE DATABASE was rejected. All owned workers/server stopped, the port was free, unavailable connection returned safe 2002, and four generated plaintext credential files were removed.

These are selected existing-route transaction cases on synthetic data, not exhaustive concurrency, hosted-engine, throughput, browser/camera/device, offline interoperability or customer tenant-isolation proof. Online admission still has no request idempotency key: retrying a lost successful response can consume more allowance while capacity remains. Existing event-row serialization is unchanged. Real ownership/platform approval, customer database/private-file restore, maintenance fencing and runtime tenant policies remain open; Phase 3 remains IN PROGRESS. [Guide](../MYSQL_ADMISSION_REHEARSAL.md).

## P3-011 — dormant authority reader actual MySQL visibility

Status: COMPLETED for selected synthetic cases (2026-10-19). Depends on P3-008 source gate and P3-010 isolated engine lifecycle verification. No runtime authorization, action policy, real grant or membership activation.

Objective: verify selected primary-read, revocation, native-value and caller-transaction visibility behavior of the unchanged dormant authority resolver on synthetic actual MySQL.

| Field | Contract |
|---|---|
| Scope | TEMP-only harness/server/fixtures/evidence plus dated docs. No application, migration, HTTP/CLI, dependency, frontend or Android change planned; reproduced source defects require regression/full-suite verification |
| Isolation | Fresh private task-owned MySQL 8.4.11, loopback 127.0.0.1:43491, MySQLX disabled, no Windows service. Two exact generated fixture databases and scoped account; verify UUID/datadir/bind/port/version/session settings. Preserve configured DBs, XAMPP, other project data, .env/app key/build/dependencies |
| Fixtures | Full repository schema plus synthetic legacy/workspace records in primary fixture; separate minimal three-table diagnostic fixture permits duplicate/malformed membership values without changing repository schema. No real identities/ownership inferred |
| Reads | Unchanged AuthorityContextResolver and one retained reader instance; independent actual writer connection commits account/platform/member changes. Prove observed getter separation outside a caller transaction, no omitted-workspace inference, missing/duplicate/malformed state refusal and safe exceptions |
| Primary routing | Divergent actual shadow read PDO and a throwing read-PDO closure must not supply either selected row or metadata authority. Explicit supplied connection must work with an unavailable default; no default fallback/change |
| Visibility | Under explicit REPEATABLE READ caller transaction, establish snapshot, commit independent revocation, document retained snapshot facts and post-caller-rollback freshness. Under READ COMMITTED, verify subsequent calls observe committed changes while preserving caller transaction. No global fence/atomic authorization guarantee |
| Conservation | For every resolver invocation compare all fixture rows before/after, query/transaction activity and default/connection state. Require no DML/DDL/locks or resolver-owned transactions; after deliberate fixture changes are restored, complete baseline snapshot must match |
| Lifecycle/evidence | Bounded commands, private generated credentials absent from stdout/argv/source, outer finally stops only identity-verified owned server; prove free port, safe unavailable behavior and remove four plaintext credential files. Reuse current 795-test gate only after source preservation, lint harness, route/CLI/link checks |

Acceptance criteria:

- Verify independent actual connection identities, scoped fixture privileges and exact server/session isolation before any synthetic mutation.
- Verify role/platform/membership separation, same-reader committed revocations, primary-only metadata/row routing and strict malformed/duplicate/unsafe-ID handling.
- Verify documented caller snapshot behavior under REPEATABLE READ and READ COMMITTED without resolver transaction/lock activity or default fallback.
- Preserve all rows across each read and restore every deliberate fixture mutation to the baseline; retain safe private exception/context behavior.
- Stop the owned server, clear generated plaintext credentials, validate unchanged source and route/CLI/link evidence, and record dated acceptance/evidence/limits.

Limits: selected MySQL 8.4.11 observations do not define action permissions, activate memberships, fence concurrent changes, invalidate a retained context or prove hosted/MariaDB/replica deployment behavior. Real owners/platform approval, customer restore and runtime tenant-policy gates remain open. Phase 3 remains IN PROGRESS.

## IMPLEMENTED — P3-011 evidence

All 34 selected actual MySQL 8.4.11 resolver observations passed using one retained resolver, a primary connection, a separate committed writer and a diagnostic shadow connection. The primary fixture applied 27 repository migrations/31 tables; the shadow fixture used only the three required tables. Native role/platform/membership separation, missing context, committed flag/member/account changes, duplicate/malformed membership state, oversized unsigned membership ID, removed parent/account and strict input/privacy checks passed.

A divergent actual shadow read PDO did not supply authority. A throwing read-PDO closure remained unopened across row and metadata reads (zero opens). The explicit reader worked with an unavailable configured default and preserved that default. Snapshots of all rows and SHOW CREATE TABLE output across both fixtures were identical immediately around every resolver invocation; captured SQL/transaction events showed no resolver writes, locks or transaction control.

Under caller-owned REPEATABLE READ, an established snapshot retained earlier platform/member facts after an independent writer committed revocation. The writer committed while the reader transaction stayed open, without waiting on resolver record locks. After caller rollback, the same resolver/connection observed the revocation. Under READ COMMITTED, the next resolve inside the same caller transaction observed committed account deactivation. Both cases preserved caller transaction level and PDO state. Every deliberate fixture mutation was then restored to the complete original row/schema snapshot.

Application source, migrations and tests were unchanged across all 342 captured baseline paths outside task docs. The current P3-008 full result **795 tests / 12,771 assertions** (127.333681s, zero errors/failures/skips) was reused after preservation checks; no new full-suite run is claimed. Four TEMP PHP files passed syntax checks, three PowerShell scripts parsed, all 97 routes and existing read-only command options/exits and 31-table empty-schema inventory smoke passed. Independent read-only harness review found no blocking issue; no application repair was required.

The new private task server bound to 127.0.0.1:43491, disabled MySQLX and registered no Windows service. UUID/datadir/version and primary strict SQL/utf8mb4/UTC/FK settings were checked before synthetic writes; independent connections used the same verified UUID and exact fixture database. The generated application account was scoped to two exact databases and unscoped CREATE DATABASE was refused. The previously verified Oracle distribution archive hash remained unchanged. The owned server stopped cleanly, the port was free, unavailable connection returned safe 2002 and all four generated plaintext credential files were removed.

This is selected synthetic MySQL visibility evidence, not runtime authorization, an action permission or a revocation fence. An old retained context remains old, and caller transaction isolation controls visible rows. Different queries in a resolver invocation are not a global atomic authority snapshot. Manager action policy, platform support bypass, ownership transfer/last-owner rules and runtime tenant enforcement remain undefined. Real owner/platform approval, customer database/private-file restore, membership activation and maintenance fencing remain open. Phase 3 remains IN PROGRESS. [Guide](../MYSQL_AUTHORITY_REHEARSAL.md).

## P3-012 — current-state and operator documentation clarity

Status: COMPLETED in documentation (2026-10-19). Depends on completed P3-011 evidence. Documentation only; no phase advancement or application behavior change.

Objective: remove material contradictory current-state claims and explain remaining ownership/restore requirements in ordinary language without asking for unknown technical paths.

Acceptance criteria:

- Check current authentication/API/workspace claims against source and the latest preserved route/test evidence; retain dated historical outcomes as history.
- Correct stale current scanner/API and database-verification wording without claiming Android scanner endpoints, deployed credentials, customer isolation or real restore proof.
- Add a concise linked plain-language status page describing existing behavior, dormant preparation, selected verification and information needed before changing real ownership.
- Record exact task evidence, validate documentation references/whitespace and preserve all captured application/config/build/dependency files. No real DB access, grants, runtime wiring, tests/build/dependency changes or messages.

Limits: documentation clarification cannot establish legitimate owners, platform approval, target hosting, actual database/file restoration or maintenance fencing. Phase 3 remains IN PROGRESS.

## IMPLEMENTED — P3-012 evidence

Added a linked plain-language status page explaining existing features, dormant workspace preparation, selected test evidence and the real owner/platform/restore/change-window facts needed before real-data changes. Corrected current scanner API registration, precise scanner token login/me versus logout conditions, dormant ownership versus runtime policies, historical 29/current 31 table counts and configured-database versus synthetic-engine evidence. Historical audit dates/results remain identified.

All 343 captured pre-existing paths were compared; only documentation changed. All 97 documented route method/path combinations match the preserved runtime snapshot exactly. Documentation links and whitespace passed; independent read-only review found no blocking overclaim. The unchanged-source 795-test / 12,771-assertion gate was retained. No backend test/build/dependency run or application/database/service/grant change was needed for this documentation-only task. [Plain-language status](../PROJECT_STATUS.md).

## P3-013 — existing CSV download formula hardening

Status: COMPLETED in source/tests (2026-10-20). Cross-cutting repair of recorded legacy risk R10, preserving existing report authorization/filtering; no Phase 5 domain extraction or customer workflow activation.

Objective: harden the five existing administrator CSV downloads against formula-looking untrusted cells while preserving stored data and established report shapes.

| Field | Contract |
|---|---|
| Scope | Existing AdminController CSV writer and focused HTTP regression tests plus dated docs; no routes/API/schema/dependencies/frontend/localization/Android/tenancy/permission changes |
| Output policy | CSV reports are spreadsheet-facing downloads. Prefix dangerous string cells with a TAB inside a quoted CSV field; detect ASCII/fullwidth formula prefixes, leading C0 controls and formula prefixes after whitespace/control characters. Include international +phone values. Preserve original stored values and ordinary text/counter/manual-code cells |
| Encoding | Use one private writer for both checkin and shared report paths; fputcsv with explicit comma/double-quote and empty proprietary escape, so quotes/delimiters/backslashes round-trip without cell breakout. Headers/column order, filenames/MIME, selection/filtering and access rules stay compatible |
| Limits | The TAB is part of raw CSV data and must be documented for programmatic consumers. No universal spreadsheet/parser or edit/save/reopen guarantee; actual spreadsheet UI unavailable. Existing typed XLSX export remains separate and unchanged |
| Verification | First reproduce unsafe HTTP export bytes before repair, then parse streamed output for all five routes and adversarial strings; verify normal Unicode/quotes/newlines/counters/manual codes, same-event filtering, browser role denials and full row conservation/repeatability. Run focused/full SQLite suite, formatting/syntax, unchanged route/CLI and baseline/link checks |
| Preservation | Isolated SQLite memory, array mail/cache/session, null WhatsApp and temporary Laravel storage. Preserve .env/app key, real/configured databases/services, builds/dependencies and all unrelated dirty source |

Acceptance criteria:

- Demonstrate existing formula-looking user fields reach downloaded CSV unchanged, then verify a single guarded writer protects both output paths.
- Test all five HTTP downloads, control/ASCII/fullwidth/whitespace-prefixed payloads, quote/comma/backslash/newline round-trip and international phone handling without stored-data mutation.
- Preserve normal row/header/filter/filename/MIME/role contracts and numeric counters/manual-code values; retain XLSX behavior.
- Pass focused/full regression, scoped formatting/syntax, route/CLI, source preservation and documentation checks; record evidence and precise remaining spreadsheet-client limitations.

Reference: [OWASP CSV Injection](https://community.owasp.org/attacks/CSV_Injection). Its Excel-oriented TAB mitigation informs this bounded download policy; it does not imply universal spreadsheet safety. Phase 3 real ownership/restore/platform/fencing gates remain open.

## IMPLEMENTED — P3-013 evidence

The pre-fix HTTP regression reproduced unguarded formula cells in all five CSV downloads (5 failures / 35 assertions). After repair, focused report/CSV/checkin/selected-event/XLSX checks passed **61 tests / 569 assertions**. The complete isolated backend suite passed **828 tests / 13,004 assertions** (121.874075s), with no errors/failures/skips. Added 33 HTTP cases covering five routes, 23 cell payloads, repeatability, whole-database row conservation and existing browser authorization.

All checkin/report CSV output now uses one private writer. Formula-looking ASCII/fullwidth strings, formula prefixes after whitespace/control characters and leading C0 control bytes (0x00–0x1F) receive a TAB prefix in the exported quoted cell. This includes international +phone values. Ordinary Unicode/text, numeric counters, headers, column order, selection/filtering, filenames and MIME remain compatible. An explicit empty fputcsv escape preserves standard quote/comma/backslash/newline round-trip; stored guest/QR/manual-code/history values do not change. Typed XLSX export is unchanged.

Compared all 344 pre-existing baseline paths and checked new additions. Only the existing controller CSV call sites/new helper, one new test file and task docs changed. Removing the helper and reversing four call substitutions reconstructs the original controller exactly after line-ending normalization. New-test Pint and both PHP syntax checks passed; the inserted method matches its Pint-formatted fragment while unrelated pre-existing controller formatting was preserved. Runtime remains 97 routes, with exact documented method/path agreement; existing read-only CLI options/exits and 31-table inventory smoke passed. No configured database, service, .env/app key, build, dependency, tenant policy, UI or Android change occurred.

CSV has no native cell type. The TAB remains part of raw exported data, so programmatic consumers must account for this documented report format. Actual Excel/LibreOffice rendering and edit/save/reopen behavior were not verified; no universal spreadsheet/parser guarantee is claimed. This repair does not enable customer tenancy or close the real ownership/platform/restore/fencing gates. Phase 3 remains IN PROGRESS. [CSV guide](../CSV_EXPORTS.md).

## P3-014 — configured connection availability refresh

Status: COMPLETED as a bounded diagnostic (2026-10-20). Diagnostic only after P3-013 source/test completion; no customer inventory, ownership change or service startup.

Objective: refresh the dated configured-connection availability evidence through one fixed harmless SELECT, preserving the actual database and all configured credentials.

Acceptance criteria:

- Use the existing explicit Laravel mysql connection settings, without printing host/database/user/password/DSN or changing .env/app key. Check cached-config presence; use temporary process-local storage/log/mail overrides and a bounded connection timeout.
- Execute only SELECT 1 on an available connection. Do not inspect customer tables/owners or run inventory without an established read-restricted account; do not migrate/seed/repair/start services or generate real grants.
- Report a fixed safe availability/unavailability status and exit code without raw exception/SQL details; treat either observed outcome as a completed diagnostic, never rollout readiness.
- Compare all source/config/build/dependency baseline files, retain current 828-test gate only if source is unchanged, validate affected docs/links/whitespace, and record the dated observation and remaining real-data gates.

Limits: availability proves neither correct target identity, read-only privileges, actual schema/data, legitimate owners, platform approval, restored backups nor maintenance fencing. Phase 3 remains IN PROGRESS.

## IMPLEMENTED — P3-014 availability observation

The 2026-10-20 fixed SELECT-only configured mysql diagnostic returned sanitized database_unavailable / driver 2002 and exit 2 during connection setup. No connection succeeded and zero application SELECT statements completed. Cached application config was absent; existing DB_* / DB_URL settings and credentials were preserved. Normal connector initialization would be session-only if a connection succeeded. No customer inventory or target/account/schema/owner validation was performed.

The TEMP PHP diagnostic passed syntax checks and emitted only a fixed safe status; no host/database/user/password/DSN, raw exception, SQL trace or customer data was printed. All 346 captured pre-existing paths remain unchanged outside task docs, including application source/tests, .env/app key, builds and dependencies. The existing 828-test / 13,004-assertion source gate was retained after preservation checks; no new backend suite is claimed for this TEMP/docs-only diagnostic. Local links/whitespace passed. No migration, seed, repair, grant or service startup/shutdown command was issued.

Completing this bounded diagnostic does not make the connection available or complete Phase 3. Actual customer inventory still requires an established read-restricted account and intended target; legitimate owners/platform staff, database/private-file restoration and a controlled change window remain unverified.

## P3-015 — default demo seeder safety

Status: COMPLETE (2026-10-20). Cross-cutting repair of the default DatabaseSeeder portion of recorded R09; no production bootstrap, real seeding, data recovery or tenant workflow.

Objective: stop the default demo seeder from deleting existing data or creating known-password samples outside exact local/testing environments.

| Field | Contract |
|---|---|
| Source scope | DatabaseSeeder, focused isolated tests, root README and dated docs. Preserve sample record fields/counts on allowed fresh-target runs; no model/schema/route/API/dependency/frontend/Android changes |
| Environment | Strict local/testing allowlist as first operation in run(), before database/FK/hash/model work. Production --force/direct invocation and staging/unknown/mis-cased labels must still refuse with fixed safe message |
| Existing data | Remove all FK-disable/enable and delete operations. Within one DB transaction, require all six formerly deleted target tables (scan_logs, admissions, checkins, qr_codes, guests, users) to be empty, using primary reads; refuse populated targets without writes. This is not whole-database emptiness or target identity verification |
| Allowed fixture | Single transaction contains unchanged sample inserts/model-side effects. Empty-target local/testing creates the same two users, six guests/QRs and two admission/checkin/log rows; repeat run refuses rather than resetting data. Injected mid-insert failure must roll back all rows, including any observer-created event, with FK state unchanged |
| Verification | Reproduce unsafe outside-environment seeding only on isolated SQLite memory, then verify exact environment/--force refusal before DB/Hash work, each populated target, rerun conservation and failure rollback. Run focused/full regression, scoped formatting/syntax, route/CLI/source/docs checks; no actual configured DB seeding or migrations |
| Remaining risks | APP_ENV is trusted configuration, not a verified database identity. Separate recovery scripts/factories and already-existing known-password accounts remain outside this repair. No general concurrent-seeding/global-fence guarantee; real admin creation and credential revocation need separate reviewed workflows |

Acceptance criteria:

- Prove default seeding cannot bypass exact local/testing protection through --force or direct calls, before any DB/hash work.
- Preserve every existing target row and FK state; remove destructive operations and reject each populated target without DML.
- Preserve intended local/testing fixture values/counts, refuse reruns, and roll back a forced mid-insert failure atomically in the tested engine.
- Pass focused/full regression, formatting/syntax, unchanged route/CLI and preservation/link checks; update README with optional empty-target local/testing scope and exact remaining risks.

Limits: all executable seeder checks use disposable SQLite fixtures, not the configured/customer database. Existing real demo credentials, recovery --apply writers, hosted/other-engine behavior and real ownership/restore/platform/fencing gates remain unverified. Phase 3 remains IN PROGRESS.

## PLANNED — later tasks

P3-002 has prepared the dormant additive schema above. P3-003 has prepared explicit proposal validation and the backfill prerequisites above; actual application remains a separate gated task. P3-004 has prepared private plan identity and dormant ledger storage without a writer. P3-005 has prepared a dormant transactional workspace backfill service with isolated retry/conservation verification. P3-006 has rehearsed the recorded synthetic fixture on a separate disposable actual engine; real owner/restore/fencing and reachable-writer gates remain separate. Real inventory/backup/owner mapping must precede any actual rollout/backfill. Separate reviewed contracts must cover explicit platform authority, resumable mapping, record conservation and rollback. Phase 3 is not complete when this diagnostic tool passes.

## REQUIRES VERIFICATION — limits

SQLite fixtures prove bounded tool behavior only; P3-006 adds recorded synthetic MySQL8.4.11 DDL/constraint/lock/restart/SQL-restore evidence. Configured customer schema/collation/constraints, live counts, comprehensive simultaneous writes, customer database/file restoration, owner mapping, offline file/provenance and production runtime remain open. Use a database account restricted to read access for a real inventory; operator connection configuration must target the intended database. See [migration plan](../SAAS_MIGRATION_PLAN.md), [database conventions](../DATABASE_CONVENTIONS.md) and [tenancy](../MULTI_TENANCY.md).

### P3-015 verification result

The pre-fix direct-production test reproduced unsafe work on an empty isolated SQLite fixture: 1 test / 7 assertions failed, with 89 SQL statements attempted. After repair, focused checks passed **41 tests / 678 assertions** and the complete backend suite passed **849 tests / 13,574 assertions** (132.606666s), with zero errors/failures/skips. All 21 new seeder cases passed in both runs: exact environment/direct/forced refusal before SQL/transaction/hash work, each populated target, allowed sample compatibility, repeat refusal and injected third-QR failure with full row rollback including the observer-created event, followed by successful retry.

Scoped Pint and both PHP syntax checks passed. The original sample insert body is preserved exactly after removing one indentation level; destructive resets and FK toggles are absent. All 350 captured pre-existing paths were compared; only the default seeder, root README and task docs changed, with one new test file. Existing application/model/schema/routes/scripts, .env/app key, builds and dependencies were preserved. Runtime remains 97 routes with exact documented method/path agreement; read-only CLI options/exits and the 31-table empty-schema inventory smoke passed. Local documentation links and whitespace passed. No configured database migration/seeding, real account change, external message, service, frontend, Android or dependency change occurred.

The environment label is trusted configuration, not verified database identity; six empty target tables do not mean the whole database is empty. This establishes one-invocation transaction behavior on SQLite, not concurrent seeding, a global maintenance fence or hosted-engine proof. Existing demo accounts, test factories, private backup artifacts and the separate scripts/recover-wedding-data.php --apply writer remain outside the repair. R09 remains partly addressed. Real ownership/platform approval, customer database/private-file restore and maintenance fencing remain open; Phase 3 remains IN PROGRESS. [Guide](../DEMO_SEEDING.md).

## P3-016 — synthetic MySQL default-seeder verification

Status: COMPLETE (2026-10-20). Bounded engine follow-up to P3-015; no application repair, live data recovery, production bootstrap or phase advancement.

Objective: verify selected default-seeder refusal/conservation/rollback cases on actual InnoDB using invented data and fresh task-owned schemas.

| Field | Contract |
|---|---|
| Source | TEMP harness/lifecycle and dated docs only; preserve all app/tests/migrations/scripts/config/build/dependency files and root setup |
| Dependency | P3-015 completed source gate: 849 tests / 13,574 assertions; read-only reuse of previously identified Oracle MySQL 8.4.11 distribution |
| Isolation | Fresh private TEMP datadir/logs/PID/credentials, exact generated schemas fresh/rollback, loopback port43492, no Windows service or MySQLX; verify UUID/datadir/version/settings before fixture writes |
| Cases | Exact outside-environment refusal before SQL/transaction/hash work; six populated-target and repeat refusal with full row conservation; original allowed sample counts/fields; injected third-QR insert failure with complete InnoDB row rollback including observer event, then successful retry |
| Safety | Process-only Laravel DB/storage/mail/cache/session overrides; generated account scoped to exact fixture databases; normal FK enforcement; no configured DB/.env/key/service or customer data changes |
| Lifecycle | Finally stop only identity-verified owned server; prove port free, safe unavailable connection and remove four generated plaintext credential files |
| Acceptance | Selected case aggregate evidence, PHP/PowerShell syntax, source preservation, route/CLI/docs checks; retain prior full gate only after unchanged-source checks |
| Limits | Allow MySQL AUTO_INCREMENT gaps after rollback. No concurrent-seeding/global fence, hosted-engine, real database identity/owner/account cleanup/restore or production workflow guarantee |

API/UI/security impacts: no routes, payloads, policy, grants, memberships, frontend, Android, model or schema changes. R09 recovery/existing-account/private-backup risks remain open. Phase 3 remains IN PROGRESS.

### P3-016 verification result

All **16 selected actual MySQL 8.4.11 cases** passed (1774 harness assertions) across two fresh task-owned schemas, each with 27 repository migrations and 31 InnoDB tables. Six production/staging/direct/forced calls across empty and populated states refused before SQL, transaction or hash work. Each of the six populated sample targets refused with complete row/DDL conservation and no seeder DML; an allowed local run preserved the original samples, and repeat refusal preserved all 31 tables.

An injected failure before the third QR insert occurred after positive partial counts in users, guests, QR codes, all three history tables and the observer-created default event. InnoDB rolled back every fixture row, including that event, to the complete pre-call 31-table snapshot; Laravel/PDO transaction state returned to idle and foreign-key enforcement stayed enabled. The same testing path then succeeded after the fault callback was disarmed. SHOW CREATE TABLE definitions matched with only the AUTO_INCREMENT option excluded: sequence gaps after rollback are permitted and no sequence restoration is claimed. Unrelated tables and the separate fresh schema remained unchanged.

The previously identified Oracle-signed distribution retained its expected archive SHA-256 and version. A new private TEMP datadir/log/tmp/PID/profile bound only to 127.0.0.1:43492, with MySQLX disabled and no Windows service. UUID/datadir/version and strict SQL/utf8mb4/UTC/FK settings were checked before fixture writes. Generated application credentials were scoped to the two exact databases; unscoped CREATE DATABASE was refused. Only the verified owned server was stopped; its port was free, a fresh connection returned safe driver 2002, and all four generated plaintext credential files were removed. No external messages were sent.

All 352 pre-existing baseline paths were compared and new additions checked; application source/tests/models/migrations/scripts, root README, .env/app key, builds and dependencies were preserved. The existing P3-015 **849-test / 13,574-assertion** full gate was reused only after unchanged-source comparison; no new full-suite run is claimed. Three TEMP PHP files passed syntax checks and three PowerShell scripts parsed. All 97 documented route method/path pairs matched runtime registration; read-only CLI options/exits, 31-table empty-schema inventory, documentation links and whitespace passed. Independent read-only harness review found no remaining blocking issue before execution.

This verifies selected single-process seeder cases on the recorded synthetic MySQL build. It does not prove concurrent seeding, a global maintenance fence, power-loss durability, another hosting engine, database identity from APP_ENV, real administrator provisioning or credential revocation. Existing known-password accounts, separate recovery --apply, factories and private backup handling remain outside this repair. No configured/customer database, XAMPP/other project service, ownership/grant, frontend/Android/dependency change or deployment occurred. Real ownership/platform approval, customer database/private-file restoration and maintenance fencing remain open; Phase 3 remains IN PROGRESS. [Guide](../MYSQL_DEMO_SEEDING_REHEARSAL.md).

## P3-017 — portable-backup Git exclusion

Status: COMPLETE (2026-10-20). Minimal cross-cutting repository protection for the recorded R09 backup-directory gap; no data recovery or phase advancement.

Objective: exclude the existing root portable-backups directory from ordinary Git discovery while preserving backup data and the repository index.

| Field | Contract |
|---|---|
| Scope | Append exact /portable-backups/ directory rule to root .gitignore and update affected docs; preserve all existing ignore bytes |
| Verification | Reproduce absent exclusion in a TEMP-only Git fixture using copied original rules; verify per-path root/nested positives and outside-root negatives with git check-ignore --no-index; compare original rules/preservation baseline and index hash/length/mtime |
| Private data | Do not traverse/read/print/copy/delete/rename backup contents. Existing tracked paths checked only as a count; no git add/rm/update-index/commit/history changes |
| Regression | No application behavior change; retain P3-015 full gate and P3-016 route/CLI snapshot only after captured source/config/build/dependency preservation. Validate local docs/whitespace |
| Limits | Ignore rules do not purge history, revoke secrets, protect/encrypt stored files, prevent forced add or exclude arbitrary copied/renamed/nested backups from deployment packages. R09 remains partly addressed |

API/UI/database/security impact: no routes, app policies, schemas, credentials, configured databases, services, frontend, Android or dependencies changed. Actual owner/platform/restore/fencing gates remain open; Phase 3 remains IN PROGRESS.

### P3-017 verification result

The copied original ignore rules left all four invented backup paths unignored in a TEMP-only Git fixture. After appending the exact root /portable-backups/ rule, all **11 per-path probes passed in both the temporary fixture and actual repository**: root directory/nested SQL/ZIP/binary probes matched that exact .gitignore rule, while similar names, nested directories elsewhere and ordinary source/setup paths remained unmatched. No persistent probe files were created in Harusi.

All 359 captured pre-existing paths were compared. Only .gitignore and affected docs changed; the original ignore bytes remain an exact prefix with only one appended directory rule. The resolved repository index retained its SHA-256, byte length and modification time; indexed portable-backup path count was zero before and after. Commands used read-only Git operations with optional locks disabled; no add/rm/update-index/commit/history operation occurred. Backup contents were not traversed, opened, printed, copied, deleted or renamed; no content-level backup hash or restoration is claimed.

Application source/tests/models/migrations/scripts, public/index.php, root README, .env/app key, builds and dependency files were preserved. The existing P3-015 849-test / 13,574-assertion gate and P3-016 97-route/31-table CLI evidence were retained only after source comparisons; no unnecessary new backend suite, build or CLI run is claimed. Local links and whitespace passed. No configured database, service, account, ownership/grant, API/UI/Android/dependency change or deployment occurred.

The rule reduces accidental inclusion during ordinary Git discovery/add; forced add can bypass it. It does not remove already tracked/history content, revoke/rotate credentials, encrypt/protect stored files, validate backups/restore or exclude backups from arbitrary packaging/deployment. It covers only the root portable-backups directory, not renamed/copied/nested backups elsewhere. Existing demo accounts and the separate recovery --apply writer remain outside this task; R09 stays partly addressed. Real ownership/platform approval, customer database/private-file restore and maintenance fencing remain open; Phase 3 remains IN PROGRESS.

## P3-018 — synthetic MySQL recovery/token contention

Status: COMPLETE (2026-10-21). Selected existing identity-boundary engine verification; no Phase 4 policy, production bootstrap or application repair.

Objective: verify existing user/reset-token locking and credential/token conservation under selected independent-worker reset/device-login orderings and one worker disconnect.

| Field | Contract |
|---|---|
| Scope | TEMP helpers/lifecycle/results plus dated docs; preserve all application/tests/migrations/scripts/config/ignore/index/build/dependency files |
| Isolation | Identified Oracle MySQL 8.4.11; new private TEMP datadir/log/tmp/PID/credentials, four exact generated synthetic schemas, loopback43493, MySQLX disabled, no Windows service; verify UUID/datadir/settings before writes |
| Workers | Eight independent PHP HTTP-kernel workers across reset/reset, reset-before-old-password-login, old-password-login-before-reset and killed-reset/retry cases. Only nonce/case/mode identifiers in argv; no secrets/response bodies in reports |
| Ordering | Pause only after actual password UPDATE or token INSERT inside the held transaction. Observe performance_schema lock waits between owned thread IDs and precommit full-row invisibility before release |
| Outcomes | Exactly one reset wins; waiting replay/old-password login refuses with no DML; an earlier valid issued token is later revoked by reset. Full snapshots preserve every unrelated row and all immutable target-account fields |
| Disconnect | Identity-verify only the owned holder before termination; observe thread disappearance and exact whole-row rollback, then successful reset retry with original reset token |
| Lifecycle | Finally stop owned workers/server, prove port-free/safe-unavailable, remove four generated plaintext credential files; no configured DB, .env/app-key or other service change |
| Acceptance | Four cases/eight workers, actual lock waits, safe HTTP results and precise password/remember/reset/device-token postconditions; PHP/PowerShell syntax, route/CLI/docs and source preservation. Reuse prior 849-test gate only if unchanged |
| Limits | Selected synthetic engine interleavings, not exhaustive races, caller-retained snapshots, power-loss durability, revocation of already-running requests, real SMTP/hosting/device or owner/restore/fencing proof |

API/UI/security impacts: preserve current envelopes/statuses, CSRF/session/token behavior, schemas/models/routes/policies/grants/memberships and customer access boundaries. Phase 3 remains IN PROGRESS. TEMP folder identifier retains 20261020; task contract/execution date is 2026-10-21.

### P3-018 verification result

All **four selected actual MySQL 8.4.11 cases / eight independent PHP HTTP-kernel workers** passed across four fresh synthetic schemas (27 repository migrations / 31 tables each). Three pairs observed actual owned-thread users RECORD lock waits and complete precommit row invisibility. Reset/reset ended at 302 success / 422 generic replay refusal; reset-before-old-password login ended at 302 / 401; old-password login-before-reset ended at 201 / 302, with its newly issued token then removed by the reset. Accepted calls executed exactly three DML statements; refused contenders executed zero.

Each completed reset replaced only the target account password/remember token/updated_at, accepted the new password and rejected the old, consumed the target reset row and removed all target device tokens. The unrelated account, its reset/device rows and every other row across all 31 tables matched the baseline exactly, including guest manual code, QR token, counters and history. SHOW CREATE definitions matched with allocator counter values excluded; no AUTO_INCREMENT restoration is claimed.

Terminating only the identity-verified owned reset worker after its completed uncommitted password/remember UPDATE and before token deletion caused complete database row rollback. Its MySQL thread disappeared and the full prior snapshot was restored, including password/remember/reset/device rows; the original reset token then succeeded on a fresh retry worker. Bounded polling accounted for rollback visibility after thread teardown.

The existing Oracle signature/version and distribution archive SHA-256 were verified. A new private TEMP datadir/log/tmp/PID/profile server bound only to 127.0.0.1:43493, disabled MySQLX and registered no Windows service. UUID/datadir/version and strict SQL/utf8mb4/UTC/FK settings were checked before synthetic writes. Generated application grants covered only four exact fixture databases; unscoped CREATE DATABASE was refused. Owned workers/server stopped, independent process/port checks passed, a fresh connection returned safe 2002, and four generated plaintext credential files were removed. No passwords, reset/bearer tokens, raw rows or private connection context were printed in results; messaging fakes observed no outbound messages.

All 359 captured pre-existing paths were compared and additions checked; application source/tests/models/migrations/scripts, root README/ignore rules, public/index.php, .env/app key, builds and dependency files were preserved. The existing P3-015 **849-test / 13,574-assertion** source gate was reused after unchanged-source checks; no new full-suite run is claimed. Four TEMP PHP files passed syntax checks and four PowerShell scripts parsed; independent read-only helper review found no blocking issue. Runtime/documented registration matched all 97 method/path pairs; existing read-only CLI options/exits and 31-table inventory smoke passed. Documentation links and whitespace passed.

These are selected synthetic transaction schedules on the recorded MySQL build, not exhaustive concurrency, power-loss durability or another hosting environment. HTTP kernel calls used APP_ENV=testing (CSRF bypass) and array sessions/cache, so deployed browser/session/rate-limit behavior and SMTP/device delivery remain unverified. PasswordReset events dispatch synchronously inside the transaction: database rollback cannot undo arbitrary external listener effects; no such app listener was found and no external-effect guarantee is claimed. A token issued by an earlier valid login can subsequently be revoked; already-running requests are not retroactively cancelled. No configured/customer database, XAMPP/other service, account/ownership/grant, API/UI/Android/dependency change or deployment occurred. Real ownership/platform approval, customer database/private-file restoration and maintenance fencing remain open; Phase 3 remains IN PROGRESS. [Guide](../MYSQL_RECOVERY_REHEARSAL.md).

## P3-019 — legacy recovery apply environment guard

Status: COMPLETE (2026-10-21). Narrow follow-up to the recorded R09 recovery-writer gap; no production provisioning, customer recovery or phase advancement.

Objective: refuse the existing recovery --apply writer outside exact local/testing environments before path/input parsing or database/hash work, preserving current dry-run and allowed-label behavior.

| Field | Contract |
|---|---|
| Source | Existing scripts/recover-wedding-data.php guard and apply-flag ordering only, new focused tests, root README and affected docs; preserve original writer/CSV/QR validation and outputs |
| Effective environment | Use the bootstrapped Laravel environment (including effective config); exact local/testing allowlist, no wildcard/case normalization. Fixed stderr and exit2 for disallowed --apply before realpath/input/CSV/QR reads |
| Verification | Reproduce missing refusal safely on a TEMP exact script copy; real Laravel bootstrap wrappers plus isolated SQLite memory/throwing connector/query/transaction/hash spies. No configured DB, customer CSV/QR or backup content |
| Cases | Deny production/staging/unknown/miscased/blank/whitespace apply; allowed local/testing with missing paths retain usage exit1; non-apply usage unchanged. Invented valid dry-run inputs in production/local remain read-only, while production apply refuses before validation output |
| Checks | Focused/full backend regression, new-test Pint and both PHP syntax, exact original-script reconstruction after removing guard/reversing flag move, route/CLI/source/index/docs preservation |
| Limits | Trusted environment is not verified DB identity or whole-data emptiness. Existing local/testing writer still creates known-password accounts/overwrites fixed event1; no actual successful apply or production recovery safety is established. Existing accounts/history/private backup access remain separate risks |

Database/API/UI/security impacts: no migrations, schema/model/route/API/UI/Android/dependency changes or actual recovery/seeding. No existing records, .env/app key, services, grants or ownership changed. Phase 3 remains IN PROGRESS.

### P3-019 verification result

The corrected pre-fix production-apply CLI regression failed solely because the original script reached usage exit 1 instead of fixed environment-refusal exit 2 (1 test / 16 assertions / 1 failure, 0.749364s). Its real Laravel bootstrap completed with zero connection/query/transaction/connector/hash work. Early TEMP harness failures were corrected before this acceptance reproduction: Windows cache paths are explicitly recognized and isolated, and incomplete-input probes use an explicitly nonexistent base CSV instead of relying on realpath of an empty string.

After the narrow guard, focused seeder/recovery safety checks passed **42 tests / 972 assertions** (24.298359s). The complete isolated backend suite passed **870 tests / 13,976 assertions** (252.814126s), with zero errors/failures/skips. All 21 new CLI cases passed: seven disallowed environment labels plus exact local/testing across apply/read-only calls with an explicitly nonexistent base CSV, valid invented production/local dry runs and production apply with the same invented inputs.

Each new case executes a hash-exact TEMP copy of the actual script through the real Laravel bootstrap with a separate absent environment/config cache, verified TEMP cache/storage paths and a synthetic key. All five built-in database connectors are replaced by throwing spies; shutdown evidence requires one completed bootstrap and zero DB connection/query/transaction/connector/hash calls. Invented CSV and metadata-only QR bytes produce the unchanged dry-run output and remain byte-identical. No customer CSV/QR/backup contents or successful apply writer was exercised.

Removing the guard and restoring the original apply-flag placement reconstructs the original script exactly. New-test Pint and both PHP syntax checks passed. All 360 captured pre-existing paths were compared and additions checked; only the recovery entry guard/flag ordering, root README and task docs changed, with one new test file. Application models/controllers/schemas/routes, default seeder, other scripts, public/index.php, .env/app key, Git index/ignore rules, builds and dependencies were preserved. Runtime/documented registration matched all 97 method/path pairs; existing read-only CLI options/exits, 31-table empty-schema inventory, local links and whitespace passed. Independent read-only source/test review found no blocking issue.

The effective Laravel environment is trusted configuration, not database identity or owner approval. Executable CLI cases used absent config caches; cached deployment behavior was not separately exercised. Allowed local/testing behavior retains the legacy writer, including known-password accounts and fixed event-ID1 overwrite; this task does not establish safe successful apply, concurrency, production provisioning or real recovery. Existing credentials/history/private backup access, packaging and restoration still require separate handling. Dry-run output and input errors can reveal supplied names/paths; they are unchanged. The metadata-only test asset does not prove PNG/QR decoding or authenticity. No actual configured/customer DB, recovery/seeding, service, account/ownership/grant, API/UI/Android/dependency change or deployment occurred. Real ownership/platform approval, customer database/private-file restore and maintenance fencing remain open; R09 stays partly addressed and Phase 3 remains IN PROGRESS. [Guide](../RECOVERY_SCRIPT_SAFETY.md).

## P3-020 — recovery CLI input-path validation

Status: COMPLETE (2026-10-21). Small repair of the concrete CLI argument defect found during P3-019 isolation work; no production provisioning or real recovery.

Objective: return existing usage exit1 for absent/empty/repeated or wrong-type CSV/QR path options before file reading, preserving the effective-environment guard and valid input behavior.

| Field | Contract |
|---|---|
| Scope | Existing recovery script path resolver/type checks, existing CLI test file and task docs; preserve parser/writer/environment-guard body, API/UI/schema/model/dependencies and existing data |
| Defect | realpath of an empty option resolves cwd; CSV directories and repeated getopt array values can trigger reader/cast errors instead of usage |
| Repair | Resolve only nonempty string options, require regular files for both CSV inputs and directory for QR input; retain fixed usage stderr/exit1, and keep P3-019 guard first |
| Verification | Safe pre-fix TEMP copied-script regression; add twelve isolated invalid-input cases across production read-only/local apply with zero DB/hash work and input conservation; preserve existing 21 environment/dry-run cases |
| Checks | Focused/full regression, scoped test formatting and PHP syntax, exact original reconstruction outside input resolver/type condition, source/index/route/CLI/docs checks |
| Limits | No actual successful apply, customer CSV/QR/backup, database identity, cached-deployment, PNG authenticity, account cleanup or restoration guarantee |

No configured/customer database, .env/app key, service, account/ownership/grant, frontend/Android/dependency change or deployment. Phase 3 remains IN PROGRESS.

### P3-020 verification result

The pre-fix no-argument production read-only regression completed with one bootstrap and zero database/hash work, but exited 0 after a reader error instead of usage exit1 (1 test / 16 assertions / 1 failure, 29.934482s). After repair, focused safety checks passed **54 tests / 1,268 assertions** (42.461498s), and the complete isolated backend suite passed **882 tests / 14,272 assertions** (10544.650642s), with zero errors/failures/skips.

Twelve new copied-script CLI cases cover no arguments, empty options, repeated base-CSV values, a directory in either CSV slot and a file in the QR-directory slot, each under production read-only and local apply. All return only the existing usage stderr and exit1, with zero connection/query/transaction/connector/hash calls and unchanged invented input bytes. All 21 prior environment/valid-dry-run cases remain present and pass, including the production apply refusal. No successful apply or customer input was executed.

Only the strict nonempty-string path resolver and file/directory condition changed in the script; reversing those substitutions reconstructs the P3-019 script exactly. Its effective-environment guard and parser/writer body are unchanged. Removing the added test/provider block reconstructs the previous 21-case test file exactly. Scoped test Pint and both PHP syntax checks passed; independent read-only review found no blocker. All 362 pre-existing paths were compared: only this script, its existing test file and task docs changed. Root README/ignore rules, Git index, other source/tests/scripts/models/schemas/routes, public/index.php, .env/app key, builds and dependencies were preserved. All 97 documented route method/path pairs, read-only CLI options/exits, the 31-table inventory smoke and documentation links/whitespace passed.

These are option-shape checks, not file readability, authenticity, ownership, race-free filesystem access or successful recovery proof. Recorded suite duration is not an application performance benchmark. Tests use exact TEMP copies, absent verified config caches, isolated storage, synthetic keys/inputs and throwing database connectors; cached deployment behavior and real/customer recovery remain unverified. Allowed local/testing writer behavior still includes known-password accounts and fixed event-ID1 overwrite. No configured/customer database, actual recovery/seeding, service, credential/account/ownership/grant, API/UI/Android/dependency change or deployment occurred. Real ownership/platform approval, customer database/private-file restore and maintenance fencing remain open; Phase 3 remains IN PROGRESS. [Scope](../RECOVERY_SCRIPT_SAFETY.md).

## P3-021 — configured connection availability refresh

Status: COMPLETE diagnostic (2026-10-21); connection unavailable. Dated refresh of P3-014's bounded diagnostic after the source tasks; no rollout or real customer-data inventory.

Objective: establish today's configured connection availability using only SELECT1 and preserve all source/settings/data/services.

Scope and acceptance:

- Reuse the reviewed fixed P3-014 TEMP PHP probe, existing explicit mysql settings and a three-second PDO timeout; preserve DB_* / DB_URL / .env / app key, record cached-config presence privately, and allow only normal connector session setup plus SELECT1.
- Emit fixed safe aggregate availability/exit evidence without host/database/user/password/DSN/raw errors; no customer table/owner queries, inventory, migrations/seeding/recovery/grants or service actions.
- Compare all362 captured paths and index, retain P3-020's 882-test /14,272-assertion gate only after source preservation, and validate documentation links/whitespace.

Limits: SELECT1 does not establish target identity, read-restricted privileges, actual schema/inventory, ownership/platform approval, backup/private-file restoration or maintenance fencing. Those remain open and rollout_ready=false regardless of availability. Phase3 remains IN PROGRESS.

### P3-021 verification result

On 2026-10-21 the unchanged reviewed SELECT-only TEMP probe returned sanitized database_unavailable / driver2002 / exit2 during connection setup. Cached application config was absent; no connection succeeded and zero application SELECT statements completed. No customer tables/owners were inspected; target identity and read-restricted privileges remain unverified. DB_* / DB_URL / .env / app key were preserved, and no service startup/shutdown, migration, seeding, recovery or grant command ran.

The probe passed PHP syntax checks and emitted only the fixed diagnostic message. All 362 pre-existing paths and the Git index were compared; application/scripts/tests, root README/ignore rules, configuration, builds and dependencies were unchanged outside task docs. The P3-020 882-test /14,272-assertion source gate was retained after preservation; no new backend suite or application change is claimed. Documentation links and whitespace passed.

A completed diagnostic is not an available database or completed Phase3. Real customer inventory still requires the intended target and a verified read-restricted account; legitimate owner/platform facts, customer database/private-file restoration and a controlled change window remain open. No ownership/grants were inferred; rollout_ready=false.

## P3-022 — automatic creator-owned workspace provisioning

Status: COMPLETE in source/tests (2026-10-21). User direction: self-service customers (a person, organization or planner) manage their own workspaces/events/team without a developer preparing ownership files. An organization/planner label grants no additional platform authority.

Objective: prepare automatic ownership of a NEW workspace from an explicit trusted authenticated creator identity, with atomic setup and replay protection, before exposing any customer route.

Acceptance criteria:

- A dormant provision(Connection, creatorUserId, name, requestKey) service accepts only a positive PHP integer creator ID, valid trimmed UTF-8 name of 1–255 characters without control characters, and canonical lowercase UUIDv4 request key. Reject ambiguous input before connecting. Future callers must derive creator ID from authenticated identity, never request body.
- Add an empty workspace_provisionings ledger with restrictive creator/workspace links, unique workspace and unique creator/request hash. Preserve all existing rows/IDs; do not adopt partial/pre-existing schema. Guard down against any provisioning row. Expand count-only inventory to 32 tables without exposing keys/names/IDs.
- Require a supported FK-enabled schema with transactional tables, exact primary/unique/restrictive keys and generated IDs within PHP integer range. Reject ambient transactions before queries. Use only the explicit primary connection, with no default-connection mutation.
- Lock a fresh active supported account (current admin/scanner roles) before checking its private request hash. In one real transaction create exactly one new workspace, an active owner membership for that creator, and its private provisioning record. Do not create/change accounts, global roles/platform flags, events, scanner assignments, legacy ledgers or customer data. The new membership is deliberate self-service setup; legacy backfill memberships remain inactive.
- Exact retry by the same creator and normalized name returns the recorded workspace with zero DML. Changed payload, missing/revoked/demoted creator membership, missing parent or corrupt resolution refuses without resurrection/adoption/reassignment. New keys may create distinct same-named workspaces; names are not identity. A renamed workspace does not invalidate its original creation request.
- Same-creator contenders serialize on the user row; uniqueness is a final constraint, database conflicts refuse safely without hidden retry. Failure after any insert rolls back the entire new setup. Sanitize exceptions and avoid private data in logs/serialization.
- Verify new setup, retries, cross-account/name/key boundaries, account/member changes, primary-only reads, strict inputs/schema, rollback and complete legacy-row/schema conservation with isolated fixtures; run focused and full backend checks, syntax/Pint, route/CLI and documentation/preservation checks.

Limits: this task adds no HTTP/CLI/job caller, public registration/customer account role, action policy, portal, ownership transfer, team management, scanner grant or platform promotion. A private ledger is replay protection, not authentication. Tenant enforcement and a safe customer identity flow precede runtime exposure. Existing unmapped events remain inaccessible to customers; old ownership is never guessed. Actual configured database/restore/fencing/engine rollout remains unverified. Source preparation is not a deployed automatic workflow.

## IMPLEMENTED — P3-022 evidence

Focused workspace/ownership checks passed **287 tests / 8,225 assertions** (24.943250s); the complete isolated backend suite passed **938 tests / 15,017 assertions** (145.771976s), with zero errors/failures/skips. Task-scoped Pint and PHP syntax passed. Registered routes remain 97, and the bounded count-only inventory now catalogs 32 tables.

A fresh active supported creator account is locked before its private creator/request receipt. Exactly three inserts commit together: a new workspace, active owner membership and provisioning receipt. Matching retries use zero DML and retain timestamps. Names are labels, not identity: different keys/accounts can create separate same-named workspaces. A renamed workspace survives retry without being renamed back. Changed creation payload, missing/disabled/demoted owner membership and missing parent refuse without resurrecting authority. Creator eligibility is reread on every call. Raw allocator IDs must be positive and fit PHP integers; SQL collation cannot turn a matching altered hash into an exact retry. Hash corruption that no longer matches a request cannot be distinguished from an unrelated key; this is not a general integrity audit. No automatic database conflict retry is attempted.

The source service has no HTTP/CLI/job caller and creates no accounts or events. Existing admin/scanner roles, platform flags, scanner assignments, credentials, QR/manual codes, admission/offline history and legacy ledgers remain unchanged. Source tests use invented data and isolated SQLite connections, array messaging/cache/session and TEMP storage. The configured database remains unavailable at the P3-021 diagnostic; no configured migration, real workspace/member grant, service/dependency/build change or deployment occurred. Actual MySQL provisioning contention/DDL, customer database/private-file restoration, rollout fencing, tenant action/resource policies, customer identity/portal and public registration are not established by this task. [Source contract and limits](../WORKSPACE_PROVISIONING.md).
