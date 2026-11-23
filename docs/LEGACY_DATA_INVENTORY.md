# Legacy data inventory

## IMPLEMENTED — P3-001 (2026-10-18)

Use the CLI command against an explicitly configured connection:

```text
php artisan tenancy:preflight --connection=mysql
```

The command writes versioned JSON to stdout. It performs schema/query reads and never migrates, seeds, repairs, assigns owners, creates default events or changes the default connection. Use a read-restricted database account for a real inventory. A missing connection option is rejected; the command does not implicitly inspect the default database.

| Exit | Meaning |
|---|---|
| 0 | Bounded inventory complete, no positive findings observed |
| 1 | Bounded inventory complete, findings require review |
| 2 | Invalid/unavailable/unsupported connection or incomplete prerequisites |

Every report keeps rollout_ready=false and ownership_mapping, backup_restore and mysql_rehearsal unverified. An exit of zero establishes only these named bounded observations. The configured MySQL connection was unavailable in the recorded run (exit 2).

## CURRENT — report fields and coverage

Schema version 1 includes status; database driver/version; consistency=best_effort; known-table presence/row counts/missing required columns; known pending migration basenames, applied count and unknown count; positive findings; explicit gates and limitations. No individual names, addresses, phones, tokens/codes, IDs, password hashes, payloads, paths, credentials, raw errors or unknown stored migration names are printed. Query/connection failures return fixed generic JSON without logging the original exception.

P3-001 originally covered 26 application/framework tables plus migrations. P3-002 expanded the fixed catalog to 29 tables, adding workspace/membership counts and required ownership/platform columns. P3-004 now expands it to 31 with private ledger counts/known column gaps only; stored hashes/references and provenance are not audited or printed. [Ledger scope](OWNERSHIP_LEDGER.md). The [service](../app/Services/Tenancy/LegacyDataInventory.php) lists required columns, which are a selected preflight subset rather than a complete DDL specification. Missing prerequisites make the report incomplete and skip dependent checks. No missing migration repository is installed.

Checks cover core event/guest/QR/admission parent references, cross-event copies, scanner assignment gaps, duplicate manual-code/QR groups, four ASCII digits for string codes, pass allowance/usage bounds, admission quantities, selected unique indexes and nullable historical links. Guest/admission streaming requires a unique, non-null ID; unsafe keys produce an incomplete report. Raw MySQL index parts and SQLite partial/expression metadata prevent composite expression indexes from falsely proving single-column uniqueness.

Findings are keyed aggregate counts with blocker/review severity. Duplicate counts represent groups; index/streaming-key findings represent failed prerequisites; other data findings count affected rows. Review findings are not automatic corruption. Historical null links, wrong-event audits and retained conflicts need context. The tool never compares historical scanner events to current assignments or reconstructs counters from admission sums.

## REQUIRES VERIFICATION — limits and next steps

Observations are best effort across multiple read queries. Concurrent writes can change counts; this is not a consistent snapshot or a backup. Only selected columns/indexes/core relationships are checked. Offline tables are counted only; the tool does not audit their provenance, conflict semantics, generated constraints, encryption or files. It does not inspect every token owner, account value, collation, engine setting, index or foreign key, and it does not enforce or audit workspace membership permissions.

Actual MySQL/MariaDB compatibility, deployed permissions, schema counts, backup restoration and legitimate owner mapping require their real environments. The simulated metadata regression proves the local interpretation guard, not live MySQL behavior. Follow the [migration gates](SAAS_MIGRATION_PLAN.md), [database conventions](DATABASE_CONVENTIONS.md), [phase contract](phases/PHASE_03_WORKSPACES.md) and [evidence](PHASE_03_REPORT.md) before workspace rollout/backfill.

## IMPLEMENTED — P3-003 source/test update (2026-10-18)

P3-003 adds a separate [ownership mapping preflight](OWNERSHIP_MAPPING_PREFLIGHT.md) for private explicit proposals. It does not replace core inventory and does not approve or apply ownership/platform changes. Both tools retain unresolved real-environment gates.

## IMPLEMENTED — P3-014 configured availability refresh (2026-10-20)

The 2026-10-20 fixed SELECT-only configured mysql diagnostic returned sanitized database_unavailable / driver 2002 and exit 2 during connection setup. No connection succeeded and zero application SELECT statements completed. Cached application config was absent; existing DB_* / DB_URL settings and credentials were preserved. Normal connector initialization would be session-only if a connection succeeded. No customer inventory or target/account/schema/owner validation was performed. The TEMP PHP diagnostic passed syntax checks and emitted only a fixed safe status; no host/database/user/password/DSN, raw exception, SQL trace or customer data was printed. All 346 captured pre-existing paths remain unchanged outside task docs, including application source/tests, .env/app key, builds and dependencies. The existing 828-test / 13,004-assertion source gate was retained after preservation checks; no new backend suite is claimed for this TEMP/docs-only diagnostic. Local links/whitespace passed. No migration, seed, repair, grant or service startup/shutdown command was issued. Completing this bounded diagnostic does not make the connection available or complete Phase 3. Actual customer inventory still requires an established read-restricted account and intended target; legitimate owners/platform staff, database/private-file restoration and a controlled change window remain unverified. [Plain-language status](PROJECT_STATUS.md).

## REQUIRES VERIFICATION — P3-021 availability refresh (2026-10-21)

On 2026-10-21 the unchanged reviewed SELECT-only TEMP probe returned sanitized database_unavailable / driver2002 / exit2 during connection setup. Cached application config was absent; no connection succeeded and zero application SELECT statements completed. No customer tables/owners were inspected; target identity and read-restricted privileges remain unverified. DB_* / DB_URL / .env / app key were preserved, and no service startup/shutdown, migration, seeding, recovery or grant command ran. A completed diagnostic is not an available database or completed Phase3. Real customer inventory still requires the intended target and a verified read-restricted account; legitimate owner/platform facts, customer database/private-file restoration and a controlled change window remain open. No ownership/grants were inferred; rollout_ready=false.

## IMPLEMENTED — P3-022 catalog expansion (2026-10-21)

The expected catalog now contains32 tables, including workspace_provisionings with id/creator_user_id/workspace_id/request_hash/name_hash required columns. Only presence, count and missing-column names appear in inventory; private row contents are never emitted. This does not validate every provisioning link/hash or authorize owner creation. The configured database was not inventoried; P3-021 still reports unavailable. [New workspace creation](WORKSPACE_PROVISIONING.md).
