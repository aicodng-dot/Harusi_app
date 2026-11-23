# Ownership mapping preflight

## IMPLEMENTED — P3-003 (2026-10-18)

Validate a private explicit ownership/platform proposal against an explicitly configured connection:

```text
php artisan tenancy:ownership-preflight --connection=mysql --manifest=<private-local-file.json>
```

The command performs bounded file/schema/query reads. It has no apply option and does not create workspaces, memberships, owners or platform grants. Use a read-restricted database account for real diagnostics. Keep real manifests outside source control, public storage and shared logs; reports deliberately omit their contents and paths. A passing result establishes structural validity and the named eligibility observations; legitimate ownership and permission to grant platform access still require independent review.

| Exit | Meaning |
|---|---|
| 0 | Complete bounded eligibility/coverage observations with no findings |
| 1 | Complete observations with one or more findings requiring review |
| 2 | Invalid invocation/manifest, unavailable/unsupported connection or incomplete prerequisites |

Every result has mode=read_only and rollout_ready=false. The ownership_mapping, platform_grants, backup_restore and mysql_rehearsal gates remain unverified even after exit 0.

## CURRENT — exact manifest version 1

The following IDs and names are fictional format examples, not a real mapping:

```json
{
  "schema_version": 1,
  "workspaces": [
    {
      "reference": "legacy-family",
      "name": "Example workspace",
      "owner_user_ids": [42],
      "event_ids": [17, 18]
    }
  ],
  "platform_admin_user_ids": []
}
```

Root and workspace objects require exactly the displayed keys. Lists must be JSON arrays. Each workspace requires at least one explicit owner and event. Empty root lists are structurally valid; an empty workspace proposal can pass database coverage only when there are no unowned events. Platform accounts are never inferred from all legacy administrators.

References match [a-z][a-z0-9_-]{0,63}. Names are nonblank UTF-8, at most 255 Unicode codepoints, without control characters. IDs must be positive PHP integers; strings, floats, exponent notation, booleans and overflow are rejected. Duplicate JSON object keys, including escaped-equivalent spellings, are rejected. References and platform IDs must be unique; owners must be unique within each workspace; an event cannot appear twice anywhere. One owner may be explicitly listed in several workspaces.

Limits: 262144 bytes (256 KiB), JSON depth 8, 1000 workspaces and 10000 total ID occurrences across every list. File reads are capped even if the file grows. Only readable regular local files are accepted; URLs, stream wrappers, UNC paths and directories are rejected. Manifest parsing and fixed error responses do not log original contents or exceptions.

## CURRENT — database observations and safe output

The [service](../app/Services/Tenancy/LegacyOwnershipPreflight.php) checks a selected required-column subset of users, events, workspaces and workspace_users. Users/events need declared single-column id primary keys and no observed null IDs before selected lookups. Missing schema or unsafe keys produce incomplete status and exit 2; the tool never creates or repairs prerequisites. Account/event queries use batches of at most 500 IDs.

Selected owners must exist, be active with canonical true/1 state and have an exact supported legacy admin/scanner role. Selected platform accounts must be explicitly listed active legacy admins. Requested platform flags already set produce a review finding. Unlisted flags are neither approved nor changed. Existing global web/scanner authority remains unchanged.

Every proposed event must exist and have null workspace_id. All currently unowned events require explicit coverage, including the default event and archived/cancelled events. Missing events, already-owned selections and uncovered unowned events produce blocker findings; no catch-all ownership is inferred. An observed mapped count exceeding the earlier unowned count produces observation_changed for review.

Successful database reports contain schema_version, status, mode, database driver/version, proposal counts, coverage counts, missing_schema, positive findings, gates, rollout_ready and limitations. Coverage is null when prerequisites are incomplete. Account findings count distinct selected identities per category; owner_memberships counts occurrences across workspaces. Event findings count selected/uncovered events; key/observation findings count failed prerequisites. Finding keys are sorted for repeatability.

Reports exclude individual names, IDs, references, account values, manifest contents/digests/paths, raw SQL and exception details. Unavailable results use fixed reasons such as connection_required, unknown_connection, manifest_required, manifest_unavailable, manifest_too_large, invalid_manifest, unsupported_driver and database_unavailable. Neither command silently falls back to the default connection.

## REQUIRES VERIFICATION — scope and later writes

Observations are best effort across multiple reads, not an atomic snapshot, backup or authorization decision. The command does not audit complete DDL, existing membership provenance, all downstream/offline constraints or files. Concurrent changes may invalidate observations even when no change is detected. SQLite fixtures and simulated changes do not certify actual MySQL/MariaDB behavior.

Real inventory, legitimate ownership/platform authorization, verified database/file restoration and actual-engine rehearsal remain required before application. P3-004 prepares the private ledger and P3-005 prepares a dormant transactional service with isolated group retry/conservation evidence. A reachable writer still needs actual readiness/fencing, deliberate conflict reconciliation and operational recovery evidence. [Service boundary](WORKSPACE_BACKFILL_PREPARATION.md). Do not use workspace names or legacy admin status as ownership keys. Do not tighten nullable ownership or enable customer access on the strength of this report.

Run the separate [legacy inventory](LEGACY_DATA_INVENTORY.md) for its selected core-integrity observations. Follow the [phase contract](phases/PHASE_03_WORKSPACES.md), [migration plan](SAAS_MIGRATION_PLAN.md) and [phase evidence](PHASE_03_REPORT.md).

## IMPLEMENTED — P3-007 read-only progress inspection (2026-10-19)

The initial ownership-preflight retains its existing contract and reports already-owned event findings. Use the separate exact backfill inspector to observe an existing proposal ledger; it reports private aggregate progress without invoking a writer. Both commands now share the same bounded local-file reader. [Scope and usage](BACKFILL_PROGRESS_INSPECTION.md).
