# Read-only exact backfill progress

## IMPLEMENTED — P3-007 (2026-10-19)

Full isolated regression passed **717 tests / 10,483 assertions** (111.554235s), with zero errors/failures/skips. Focused inspection/writer/schema/proposal checks passed **169 tests / 5,677 assertions** (12.416401s). Eight scoped PHP files passed Pint/syntax; all 97 routes, new command help/missing-connection exit2 and the 31-table empty-schema CLI smoke passed. Baseline/whitespace/local-link checks passed.

The new tenancy:backfill-inspect command observes an explicit private proposal against unstarted, retained empty-plan, partial and exact ledger state. Shared exact state validation preserves dormant writer checks/locking/commit/raw-ID semantics; the bounded local manifest reader is shared with ownership-preflight without changing that command. Inspection has no writer dependency, apply call, transaction, row lock, mutation, grant, activation or approval option.

## CURRENT — invocation and private inputs

Use an explicitly configured review-copy connection and the same bounded private version1 [ownership manifest](OWNERSHIP_MAPPING_PREFLIGHT.md). Example after configuring that isolated connection:

    php artisan tenancy:backfill-inspect --connection=review_copy --manifest="D:\private\proposal.json"

No apply, approve, repair, force or activation switch exists. Missing/unknown connection is rejected before opening the manifest; URL/stream/UNC/NUL/directory paths are refused. Only a regular local file is read, bounded both by metadata and an actual MAX_BYTES+1 read. Strict JSON parsing and canonical private identity are unchanged; files and digest values are not printed or persisted by inspection.

## CURRENT — exact state and output

[LegacyWorkspaceBackfillInspection](../app/Services/Tenancy/LegacyWorkspaceBackfillInspection.php) depends on the existing schema guard and shared [OwnershipBackfillState](../app/Services/Tenancy/OwnershipBackfillState.php), never the writer. It requires the same safe six-table prerequisites, enforced FKs/transactional engines and no ambient transaction. It performs only schema/query reads with selected IDs in batches no larger than500.

Every selected owner is currently eligible; all selected events exist; all currently unowned events, including default/archived/cancelled, must be covered. Exact private plan/version/reference resolution is required for already-owned events. Committed groups must match exact byte names, exact inactive owner memberships and exact event sets/ownership. Existing workspace names never provide adoption/resolution. Missing, extra, active, changed, unknown or retargeted state refuses without repair. Nonempty platform intent is explicitly unsupported.

Successful output is schema_version1/status complete/mode read_only, progress and aggregate proposal/observed counts. proposal contains workspaces, owner_memberships and events; observed contains plan_present, workspaces_verified, workspaces_remaining, events_assigned and events_remaining. No IDs, account data, names, references, hashes, paths or SQL are exposed.

| progress | Meaning observed during this invocation |
|---|---|
| unstarted | No committed group for the exact proposal; an empty retained plan may exist |
| partial | At least one exact committed group, with some proposed groups still unstarted |
| exact | Every proposed group matches exactly. An empty proposal with no unowned events needs no plan |

Exit0 means the observation succeeded, even for unstarted/partial work; it does not mean rollout approval. Exit1/status conflict reports only an allowlisted semantic reason: eligibility_changed, coverage_conflict, ledger_conflict or platform_proposal_unsupported. Exit2/status unavailable covers invalid invocation, private-file/manifest failures, unsupported driver, invalid_prerequisites or database_unavailable. Original exceptions are discarded without logs/previous context. All outcomes retain the same unverified ownership_mapping/platform_grants/backup_restore/mysql_rehearsal gates and rollout_ready=false.

## IMPLEMENTED — conservation and regression evidence

Added33 service cases/2,938 assertions and28 command/reader cases/856 assertions. The service tests cover all progress states and reordered retries, empty proposals,11 committed-state drift cases, current owner/coverage conflicts, changed proposals, FK/schema/ambient-transaction rejection, private query failures and503-owner/505-event batches. SQL and transaction-event observation plus a writer mock prove no writes, transaction boundaries or writer invocation; full schema/all-row/timestamp snapshots and default connections remain unchanged. SQLite query observation does not independently prove MySQL locking; source review confirms inspection uses only the shared non-locking defaults.

Command/reader tests cover explicit named connections, no default mutation, unchanged manifests/configuration, exact byte limits and binary preservation, safe file/type/schema/driver/query failures, all fixed conflict exits, privacy and no writer options. Existing writer/schema/proposal regressions also pass. Independent review found no blocker. Compared327 baseline paths: only the dormant writer and old preflight command changed among existing PHP; four source and two test files were added. Configuration, app key, builds, dependencies, migrations and unrelated source/tests are preserved.

## REQUIRES VERIFICATION — operational limits

Results are multi-query observations, not a consistent snapshot, retry guarantee, legitimate-owner approval, backup evidence or maintenance fence. All four external gates stay unverified and rollout_ready=false. No configured/customer database, actual owner/platform grant, runtime writer, HTTP/API/UI/dependency/Android change or deployment was involved. Phase 3 remains IN PROGRESS.

Use the [original proposal preflight](OWNERSHIP_MAPPING_PREFLIGHT.md) for initial read-only eligibility findings, this command for exact partial/completed technical state, and the [dormant writer guide](WORKSPACE_BACKFILL_PREPARATION.md) for commit/retry boundaries. No command invokes that writer. Actual execution still needs current private inventory, reviewed owners/platform authorization, verified customer database/file restore and a cooperating-writer/maintenance fence. [Phase contract](phases/PHASE_03_WORKSPACES.md), [task evidence](PROJECT_PLAN.md), [bounded earlier MySQL rehearsal](MYSQL_REHEARSAL.md).
