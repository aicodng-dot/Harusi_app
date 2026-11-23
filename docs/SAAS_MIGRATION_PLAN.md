# SaaS migration plan

## CURRENT — legacy runtime and prepared storage

Global admins manage events. `users.event_id` assigns scanners to one event; guests/logs/QR are event-owned. P3-002 prepares dormant workspace/membership storage in repository migrations and isolated fixtures. The configured database/schema remains unverified, and runtime customer-workspace authorization is not enabled. [Audit](CURRENT_SYSTEM_AUDIT.md) and [domain model](DOMAIN_MODEL.md) describe current tables. No migration was run on the configured database during Phase 0/1.

## IMPLEMENTED — preflight tool

P3-001 provides the [read-only inventory command](LEGACY_DATA_INVENTORY.md), tested with isolated fixtures. A real configured-MySQL run is still unavailable. Tool completion does not satisfy data inventory, restoration, owner mapping or database-engine rehearsal gates.

## IMPLEMENTED — expansion prepared

P3-002 prepares workspaces(id/name/timestamps), workspace_users(required restrictive user/workspace links, owner/manager role, inactive default and unique pair), nullable indexed events.workspace_id and users.is_platform_admin default false. New relationships add no authorization or automatic ownership. Platform capability is hidden/non-fillable and excluded from existing HTTP/API inputs; legacy global admin/scanner roles remain unchanged. The expected inventory now covers 29 tables and the new columns. No default workspace, membership, event owner or platform grant is inserted. Full isolated regression passed **410 tests / 4,249 assertions** (111.846082s). Focused workspace/inventory checks passed **54 tests / 1,132 assertions** (2.762719s). Eight task PHP files passed Pint and syntax checks; scoped whitespace checks passed. Runtime retains 97 HTTP routes, and the empty-database CLI returns incomplete/exit 2 with the expanded 29-table catalog. Preparation does not satisfy the actual inventory/restore/mapping gates. [Schema/rollback contract](phases/PHASE_03_WORKSPACES.md).

## TARGET — invariants

One user can belong to multiple workspaces; one workspace can contain multiple events. Owners/managers operate tenant resources. Platform administration stays separate. Scanner assignments grant admission rights without management membership. Preserve record IDs, QR tokens, string manual codes, admission history, encrypted-file dependencies and notification provenance.

## PLANNED — migration gates

1. **Identity foundation:** restore remember support, canonical optional account phone, account state, email/phone sessions and secure recovery/tokens. Do not open registration yet.
2. **Data inventory:** establish actual schema/version/applied migrations, counts, nulls/duplicates, orphaned relations, invariant violations and backups. Current live connection is unavailable; never infer owner identity from seed/demo users.
3. **Expand:** add workspaces/memberships and nullable indexed `events.workspace_id`; preserve existing key formats. Provide explicit platform capability separately from workspace roles. Record default/legacy handling and intended FKs first.
4. **Backfill:** create a clearly identified Legacy Workspace only with verified ownership mapping. Use chunked, resumable, idempotent updates, audit mapping and counts; do not rewrite tokens/codes/history. Keep the existing global-admin workflow working while customer routes remain closed.
5. **Authorize:** policies/scopes verify active identity → membership → permission → event/workspace → resource/action. Protect files/imports/exports/reports/QR/notifications and offline sessions, not only Blade navigation.
6. **Verify:** test workspace A/B isolation, scanner event boundaries, URL/body/context manipulation, reassignment/revocation, offline locks and file access. Compare record counts/references and test real database locking on disposable MySQL/MariaDB.
7. **Contract:** only after successful backfill/isolation tighten nullability/FKs/uniques. Choose maintenance window and rehearsal from actual hosting capabilities; no broad destructive rollback.
8. **Self-service:** customer portal/events before public registration; team/scanner workflows before Android unification; engine before template catalogue; billing after stable tenancy and self-service.

### PLANNED — additive invitation/RSVP integration (2026-10-25)

The [customer-experience tasks](phases/CUSTOMER_EXPERIENCE_INTEGRATION.md) fit the existing phases: service contracts in P5, bilingual UI in P6/P8, category/minimal Event details/import in P9, onboarding resume in P10, default rendering/Quick Edit in P16/P17, secure guest access/RSVP/existing-pass display in P19, queued Meta progress/live summaries in P24 and complete acceptance in P27. Do not enable a preview CTA before the engine can fulfill it or send public links before secure guest access exists. Preserve the original 5/20/100-template stages and the Scanner Website.

Repository Event dates are currently date-only; time/timezone and category support need additive mapping, not reinterpretation of historical dates. RSVP/access records are proposals, not applied migrations. New tables must reuse the current Guest/QrCode lineage and notification delivery evidence after actual-schema review. Existing QR eligibility remains independent of RSVP by default; any future positive-response release setting must be explicit. Retain database/private-file restore, ownership, tenant isolation and hosting gates. Phase 4 authorization stays IN PROGRESS; the invitation documentation update performs no runtime or database changes.

## REQUIRES VERIFICATION — decisions before ownership changes

Actual event owners and permitted support access; email/phone collisions; account recovery for missing email; how a scanner joins multiple events; migration behavior for archived/cancelled events; retained audit data and delete rules; private QR/invitation delivery strategy; Android repository/protocol compatibility; supported cPanel cron/queue execution. No placeholder customer ownership may be promoted to an authorization truth.

## Rollback and acceptance

Rehearse [backup/restore](BACKUP_AND_ROLLBACK.md) before database rollout. Additive schema can remain while code is rolled back; never drop populated ownership columns to solve a deployment issue. Stop rollout on count mismatch, invariant failure, unauthorized access or lost offline logs. [Project plan](PROJECT_PLAN.md) controls task order; [database conventions](DATABASE_CONVENTIONS.md) controls DDL/data conventions.

## IMPLEMENTED — P3-003 source/test update (2026-10-18)

P3-003 completes repository-only explicit proposal validation. A structural/eligibility pass is not owner approval, a backup, engine proof or permission to write. Private stable plan/reference resolution and transactional event/member/platform outcomes still require separate design and verification. [Guide](OWNERSHIP_MAPPING_PREFLIGHT.md), [phase evidence](PHASE_03_REPORT.md).

## IMPLEMENTED — P3-004 source/test update (2026-10-18)

P3-004 prepares stable private plan/reference resolution only. Event/member/platform outcome journaling, locks, crash/retry semantics and deliberate changed-proposal reconciliation remain future writer work. Actual inventory/owner approval/restore/engine gates remain open. [Guide](OWNERSHIP_LEDGER.md).

## IMPLEMENTED — P3-005 source/test update (2026-10-18)

P3-005 prepares transactional workspace/member/event/resolution outcomes in dormant source. Exact completed groups are verified without rewriting; current-group failures roll back, while earlier groups survive later failure. This is not plan-wide rollback, human approval, global event-writer fencing or a complete audit journal. Actual engine/restore/ownership/fencing remain required before a reachable writer. [Guide](WORKSPACE_BACKFILL_PREPARATION.md).

## IMPLEMENTED — P3-022 self-service ownership direction

New workspace creation will automatically make its authenticated creator the initial owner; person/organization/planner labels grant no platform authority. The [dormant transaction service](WORKSPACE_PROVISIONING.md) and private retry ledger are now verified in isolated source tests (938 tests / 15,017 assertions). It creates no accounts or events and has no runtime caller. New workspace creation does not require developer manifests or legacy backfill. Existing unmapped events stay private; actual historical ownership is never inferred. Tenant action/resource policies and a safe customer account flow are the next prerequisites for in-app exposure. Existing rollout/restore/fencing gates remain open.
