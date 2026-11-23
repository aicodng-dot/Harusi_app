# Synthetic MySQL staff transaction checks

## IMPLEMENTED — P4-007 (2026-10-22)

All **four selected actual MySQL 8.4.11 cases / nine independent PHP workers** passed. Three waits were observed through performance_schema with exact requesting/blocking connection IDs, schema/table, PRIMARY index, WAITING RECORD state and canonical target key (Event301 or User221). Every synthetic schema applied the frozen 28 repository migrations and had 32 tables. Whole-fixture snapshots and table definitions were conserved outside the exact allowed changes; allocator counters were excluded from schema comparison and their restoration is not claimed.

| Case | Observed result |
|---|---|
| Scanner creation versus parent adoption | Parent validation saw an unowned event; the actual Event lock waited for the holder, then current ownership caused generic422 and zero staff DML. Only the holder's synthetic ownership change remained. |
| Older caller RR snapshot versus membership commit | The caller first observed no membership. Its User lock waited for an independent inactive-member insertion; the current locking membership read then denied404 with zero staff DML. Nested rollback preserved the caller level, followed by explicit outer rollback. |
| Credential update versus compliant membership writer | The member writer waited on the exact User until password/remember changes and target token revocation committed. Only the later inactive membership was added; a subsequent legacy update denied404. |
| Interrupted credential/token mutation | Only the verified owned worker was terminated after User update and token deletion were uncommitted. Its DB thread disappeared, the complete fixture returned to its prior state, and a fresh HTTP retry succeeded302 with exactly two DML statements. |

A fifth dedicated synthetic schema verified actual utf8mb4_unicode_ci case/padding equality before exercising the byte-exact role/status filters. Mixed-case/padded staff roles and active statuses were excluded. Real unsigned IDs of PHP_INT_MAX+1 were inserted as decimal strings and excluded from catalogue/options; a raw overflow bound target denied404, while canonical PHP_INT_MAX positive controls remained visible. Direct controller view-data reads executed zero DML and preserved the whole fixture/schema. This was not a browser/HTML/device test.

## IMPLEMENTED — isolation and verification

All **four selected actual MySQL 8.4.11 cases / nine independent PHP workers** passed. Three waits were observed through performance_schema with exact requesting/blocking connection IDs, schema/table, PRIMARY index, WAITING RECORD state and canonical target key (Event301 or User221). Every synthetic schema applied the frozen 28 repository migrations and had 32 tables. Whole-fixture snapshots and table definitions were conserved outside the exact allowed changes; allocator counters were excluded from schema comparison and their restoration is not claimed.

The existing Oracle-signed binary was reused read-only. A new ACL-restricted TEMP data directory and generated scoped credentials used loopback43494, MySQLX disabled and verified UUID/datadir/port/bind/version before writes. All owned workers and the owned server were stopped; an independent process/port check passed, unavailable connection returned safe2002, and the four task-created plaintext credential files were removed. XAMPP, other project services/data and Harusi's configured database were untouched.

The **1,240 tests / 22,336 assertions** P4-006 source gate is retained after exact source/test preservation, not presented as a newly rerun suite. TEMP PHP formatting/syntax, unchanged98 route method/path pairs, safe32-table read-only CLI checks, Git index/environment, document links and whitespace passed.

All 392 captured task-start paths were compared; only task documentation changed. No fixture/profile/credential/report payload is added to the repository. Generated secrets stayed in the private TEMP directory and were not emitted in commands or reports. Synthetic database rows/data directories remain private test evidence; no customer backup was opened.

## REQUIRES VERIFICATION — limits

These are selected schedules using invented data on a separate server, not a configured/customer database rollout or proof of every interleaving/arbitrary external writer. The dormant legacy backfill still requires its maintenance fence. Current 28-migration fresh DDL includes the empty provisioning receipt table; automatic provisioner transactions/contention/retries have not been rehearsed on MySQL by this task. Guest/offline/file lineage, write/audit/download/delete transactions, scanner fallbacks and public/static assets remain unfinished. Customer writers/signup remain closed. Actual legacy ownership/platform approval, database/private-file restore/fencing and hosting/SMTP/browser/device remain unverified. No application/test/migration/API/UI/model/default/dependency/build/environment/app-key or real account/credential/owner/grant changes and no deployment occurred.

[Staff source boundary](LEGACY_STAFF_ACCOUNTS.md), [plan](PROJECT_PLAN.md), [phase contract](phases/PHASE_04_AUTHORIZATION.md), [phase report](PHASE_04_REPORT.md), [database](DATABASE_CONVENTIONS.md).
