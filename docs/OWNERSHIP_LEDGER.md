# Private ownership plan identity and ledger

## IMPLEMENTED — P3-004 source preparation (2026-10-18)

This foundation gives a future writer a stable proposal identity and durable workspace-reference resolution. It creates no records, performs no backfill and grants no access. Actual database rollout remains unverified. [Task contract](phases/PHASE_03_WORKSPACES.md), [evidence](PHASE_03_REPORT.md).

## CURRENT — deterministic private identity

[OwnershipPlanIdentity](../app/Services/Tenancy/OwnershipPlanIdentity.php) accepts only a validated [OwnershipManifest](../app/Services/Tenancy/OwnershipManifest.php). It sorts groups by bytewise reference order and owner/event/platform ID sets with integer comparison, preserving exact names and associations. JSON formatting, object-key order and list order do not change identity; moving an event or owner to another workspace does. Whitespace and Unicode normalization differences in names remain meaningful.

Canonical version 1 has fixed root order schema_version, workspaces, platform_admin_user_ids. Each workspace uses reference, name, owner_user_ids, event_ids in that order. It uses the exact PHP JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR flag mask, retaining default U+2028/U+2029 escaping. SHA-256 covers the UTF-8 bytes prefixed by kadify:legacy-ownership-plan:v1 followed by one NUL byte. A future change to these bytes needs an explicit protocol/version decision.

The lowercase 64-character digest is private internal identity, not a signature, approval token or secrecy guarantee. The readonly object has no implicit string conversion and serializes to an empty JSON object. Only digest() explicitly exposes its value for future persistence. Current CLI reports and logs do not include it. Do not use hash possession as authorization or place real digests/inputs in source control, public files or ordinary logs.

## CURRENT — dormant storage

The [additive migration](../database/migrations/2026_10_18_010000_add_legacy_ownership_ledger.php) defines:

| Table | Fields and constraints |
|---|---|
| legacy_ownership_plans | Numeric id, required schema_version without default, required char64 manifest_hash unique, timestamps |
| legacy_workspace_mappings | Numeric id, required ownership_plan_id restrictive FK, required manifest_reference string64, required workspace_id restrictive FK and globally unique, timestamps; unique(plan, reference) |

Short explicit unique/FK names avoid generated-name limits. The plan/reference pair resolves a group within one exact proposal; workspace names are never resolution keys. The same reference may occur in different plans, while one workspace can be claimed by only one ledger mapping. A changed manifest is a separate proposal that requires explicit reconciliation; it is not automatically a retry, replacement or permission to adopt an existing workspace.

No full manifest copy, approval/status flag, Eloquent model hook, automatic rows or write/apply command is added. The ledger does not journal individual event/member/platform outcomes. Database uniqueness and foreign keys do not validate all manifest semantics, hash format or approval. A future writer must use validated identity and authoritative reviewed inputs and check current data transactionally.

The inventory's fixed catalog now contains 31 tables. Ledger coverage is counts and selected required columns only; it exposes no stored digest/reference values and does not audit provenance or approve a plan. The ownership proposal command retains its selected four-table eligibility scope. [Legacy inventory](LEGACY_DATA_INVENTORY.md), [proposal checks](OWNERSHIP_MAPPING_PREFLIGHT.md).

## CURRENT — guarded migration and rollback

The migration resolves the named Laravel connection directly and supports SQLite/MySQL/MariaDB. Before creating either table, it requires a real workspace parent table with a declared single-column id primary key and no observed null IDs, rejects existing ledger tables/views, and refuses active SQLite transactions. The migration inserts no ownership, membership, capability, workspace or ledger records.

Down requires both tables and their known columns and refuses if either table contains any row, including damaged orphan mapping data. Empty reversal drops mappings before plans. Preserve populated ledger and ownership structures when rolling application code back; do not delete records to bypass guards. DDL is non-atomic, and partial failure requires review/forward repair. SQLite test fixtures reverse the later ledger before exercising the earlier foundation migration, preserving migration chronology.

## REQUIRES VERIFICATION — application and rollout

Source tests establish canonical identity, selected schema constraints, guards and record conservation in SQLite memory only. They do not establish actual MySQL/MariaDB DDL/collation/index behavior, concurrent writes, legitimate ownership, approved platform access or database/file restoration.

P3-005 prepares a [dormant transactional workspace service](WORKSPACE_BACKFILL_PREPARATION.md) with isolated retry/conservation checks. A reachable writer still needs reviewed private inputs, actual inventory and restore/engine/fencing evidence, durable operational outcomes and deliberate conflict reconciliation. Stable resolution alone does not establish resumability or idempotent application. Registration, tenant policies and platform enforcement remain separate gates. Follow the [migration plan](SAAS_MIGRATION_PLAN.md) and [backup/rollback rules](BACKUP_AND_ROLLBACK.md).
