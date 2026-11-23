# Backup and rollback

## CURRENT

`PORTABLE_BACKUP_README.md` describes a runnable bundle containing `.env`, dependencies, Git history, uploaded/generated files and a SQL dump. A dated backup directory exists under `portable-backups/`. Its contents/restore integrity were not audited. At the 2026-10-11 audit it was not excluded by the root .gitignore. P3-017 (2026-10-20) adds the exact root /portable-backups/ directory rule; no portable-backup paths are currently indexed in this checkout. This does not establish history cleanup, encryption, deployment exclusion or recovery integrity. Do not print or publish those files.

Migration code includes data backfills and hard-deletion/cascade/restrict behavior. Production migration state is unverified; no real database migration, restore or seeding was executed in Phase 0/1.

## TARGET / PLANNED

- Before schema/ownership changes, capture encrypted DB backup, protected uploaded/private files, APP_KEY and signing/encryption keys, deployed commit/build IDs and migration list. Separate secret-bearing backups from distributable source artifacts.
- Record counts, foreign-key consistency, guest capacity violations, tokens/codes and pending offline imports without publishing guest PII. Verify checksums and restore to an isolated database/host; a file's existence is not a successful backup.
- Define retention/access based on guest privacy and operational/legal requirements before automated deletion. Include unsynchronized device logs in recovery planning; server backup alone cannot restore logs never synchronized.
- Roll back code first when safe; additive schema can remain. Do not blindly run destructive `migrate:rollback` after live backfill. Rehearse data restore in a maintenance window, preserve newer admissions/import provenance, and assess encrypted-data dependencies before key rotation.
- Stop rollout on tenant leaks, data/count mismatch, lost tokens/codes, over-admission or unreadable private files. Record the incident and deploy a reviewed corrective migration.

## REQUIRES VERIFICATION

Backup location/access/encryption, actual data owner approval for backfill, restore duration and recovery objectives, hosting quotas, offline log reconciliation, retention policy and cPanel restore capability. Audit did not certify the existing portable bundle for production recovery.

## IMPLEMENTED — P3-002 conservation guard

Workspace expansion down runs all data/schema checks before DDL and refuses when any workspace/member row, event ownership or nonfalse/unknown platform flag exists. Do not delete these records to force rollback. Retain populated expansion during a reviewed code rollback; partial/non-atomic DDL requires a rehearsed forward repair or restore. Empty reversal removes the event FK/index/column before other expansion structures; SQLite conservation tests pass, while real engine/restore behavior remains unverified.

## IMPLEMENTED — P3-003 source/test update (2026-10-18)

P3-003 ownership proposal checks perform reads only and cannot provide restore evidence or authorize a backfill. Preserve reviewed private mapping inputs separately; obtain actual database/file restoration and engine rehearsal before any write workflow. [Guide](OWNERSHIP_MAPPING_PREFLIGHT.md).

## IMPLEMENTED — P3-004 source/test update (2026-10-18)

The P3-004 ledger migration refuses any populated reversal, including damaged mapping data. Retain populated metadata with ownership structures during code rollback; empty reversal drops mappings before plans. Non-atomic partial DDL needs review/forward repair, and actual restore/engine proof remains open. [Guide](OWNERSHIP_LEDGER.md).

## IMPLEMENTED — P3-005 source/test update (2026-10-18)

P3-005 commits per workspace, not per entire proposal: current-group failure rolls back, but an empty plan and earlier committed groups may remain. Preserve/review private ledger resolution and retry only under the same approved proposal with exact current-state verification. Source fixtures do not establish real backup restoration or operational recovery. [Guide](WORKSPACE_BACKFILL_PREPARATION.md).

## IMPLEMENTED — P3-006 synthetic MySQL evidence (2026-10-18)

The same server identity and every fixture row survived restart. A scoped-account mysqldump/mysql restore into the second fresh owned schema matched all rows across 31 tables and passed the backfill schema guard. After clean shutdown, the connection failed safely with driver code 2002/exit 2; port 43484 was free and task-created plaintext profile/client/bootstrap credentials were removed. Synthetic SQL restore does not certify the existing portable bundle, customer database/private files, APP_KEY dependencies or device recovery. The first actual migration run failed with MySQL 1553 at 2026_07_23_190000: dropping the last old unique index removed the index supporting os_log_session_fk before its replacement existed. The narrow repair creates the four replacement indexes before dropping their predecessors in both directions; final names/columns/uniqueness are unchanged. Fresh history and empty down/up now pass with FK checks enabled. It does not repair an already partially applied live migration or certify populated rollback after duplicate conflict records exist. [Rehearsal scope and evidence](MYSQL_REHEARSAL.md).

## IMPLEMENTED — P3-007 read-only progress inspection (2026-10-19)

Use read-only exact progress inspection before reviewing retained plans/committed groups. Partial/exact output is technical observation only and cannot establish recovery/approval/fencing or authorize a write. Results are multi-query observations, not a consistent snapshot, retry guarantee, legitimate-owner approval, backup evidence or maintenance fence. All four external gates stay unverified and rollout_ready=false. No configured/customer database, actual owner/platform grant, runtime writer, HTTP/API/UI/dependency/Android change or deployment was involved. Phase 3 remains IN PROGRESS. [Scope and usage](BACKFILL_PROGRESS_INSPECTION.md).

## IMPLEMENTED — P3-009 selected MySQL worker evidence (2026-10-19)

Actual worker disconnect retained group1 and discarded uncommitted group2; partial inspection/resume and zero-DML retry passed. This is transaction recovery evidence, not server power-loss durability or customer database/private-file restore. This verifies selected service interleavings and a worker disconnect on the recorded synthetic MySQL build, not complete crash/durability/deadlock/race coverage, another hosting engine, maintenance fencing, customer backup/file restoration or legitimate owner/platform approval. Competing multi-group proposals may retain different committed groups; no single complete winning proposal is guaranteed in general. No automatic retry/global mutex, runtime writer, platform grant, membership activation, XAMPP/configured database change or deployment occurred. Phase3 remains IN PROGRESS. [Scope and evidence](MYSQL_CONCURRENCY_REHEARSAL.md).

## IMPLEMENTED — P3-010 selected admission evidence (2026-10-19)

Terminating only the identity-verified owned PHP holder after its uncommitted guest-counter UPDATE caused MySQL to roll back the counter/status change and leave history unchanged. Its connection thread disappeared, the complete prior committed snapshot returned, and a fresh worker then admitted exactly once. This is transaction rollback evidence, not a customer database/private-file restore or server power-loss durability test. The private server bound only to 127.0.0.1:43490 with MySQLX disabled and no Windows service. Its UUID, data directory, version and session strict SQL/utf8mb4/UTC/FK settings were checked before fixture writes. Oracle Authenticode signature and the existing distribution archive SHA-256 were verified. Generated application credentials had grants only on the five exact fixture databases; unscoped CREATE DATABASE was rejected. All owned workers/server stopped, the port was free, unavailable connection returned safe 2002, and four generated plaintext credential files were removed. These are selected existing-route transaction cases on synthetic data, not exhaustive concurrency, hosted-engine, throughput, browser/camera/device, offline interoperability or customer tenant-isolation proof. Online admission still has no request idempotency key: retrying a lost successful response can consume more allowance while capacity remains. Existing event-row serialization is unchanged. Real ownership/platform approval, customer database/private-file restore, maintenance fencing and runtime tenant policies remain open; Phase 3 remains IN PROGRESS. [Scope and evidence](MYSQL_ADMISSION_REHEARSAL.md).

## IMPLEMENTED — P3-011 actual MySQL authority visibility (2026-10-19)

The new private task server bound to 127.0.0.1:43491, disabled MySQLX and registered no Windows service. UUID/datadir/version and primary strict SQL/utf8mb4/UTC/FK settings were checked before synthetic writes; independent connections used the same verified UUID and exact fixture database. The generated application account was scoped to two exact databases and unscoped CREATE DATABASE was refused. The previously verified Oracle distribution archive hash remained unchanged. The owned server stopped cleanly, the port was free, unavailable connection returned safe 2002 and all four generated plaintext credential files were removed. Final synthetic fixture conservation is not customer database/private-file restore proof. This is selected synthetic MySQL visibility evidence, not runtime authorization, an action permission or a revocation fence. An old retained context remains old, and caller transaction isolation controls visible rows. Different queries in a resolver invocation are not a global atomic authority snapshot. Manager action policy, platform support bypass, ownership transfer/last-owner rules and runtime tenant enforcement remain undefined. Real owner/platform approval, customer database/private-file restore, membership activation and maintenance fencing remain open. Phase 3 remains IN PROGRESS. [Scope and evidence](MYSQL_AUTHORITY_REHEARSAL.md).

## IMPLEMENTED — P3-016 seeder transaction rollback (2026-10-20)

An injected failure before the third QR insert occurred after positive partial counts in users, guests, QR codes, all three history tables and the observer-created default event. InnoDB rolled back every fixture row, including that event, to the complete pre-call 31-table snapshot; Laravel/PDO transaction state returned to idle and foreign-key enforcement stayed enabled. The same testing path then succeeded after the fault callback was disarmed. SHOW CREATE TABLE definitions matched with only the AUTO_INCREMENT option excluded: sequence gaps after rollback are permitted and no sequence restoration is claimed. Unrelated tables and the separate fresh schema remained unchanged. This was invented data on a separate MySQL server, not customer database/private-file restoration or a verified backup. [Scope](MYSQL_DEMO_SEEDING_REHEARSAL.md).

## IMPLEMENTED — P3-017 Git exclusion (2026-10-20)

The copied original ignore rules left all four invented backup paths unignored in a TEMP-only Git fixture. After appending the exact root /portable-backups/ rule, all **11 per-path probes passed in both the temporary fixture and actual repository**: root directory/nested SQL/ZIP/binary probes matched that exact .gitignore rule, while similar names, nested directories elsewhere and ordinary source/setup paths remained unmatched. No persistent probe files were created in Harusi.

All 359 captured pre-existing paths were compared. Only .gitignore and affected docs changed; the original ignore bytes remain an exact prefix with only one appended directory rule. The resolved repository index retained its SHA-256, byte length and modification time; indexed portable-backup path count was zero before and after. Commands used read-only Git operations with optional locks disabled; no add/rm/update-index/commit/history operation occurred. Backup contents were not traversed, opened, printed, copied, deleted or renamed; no content-level backup hash or restoration is claimed.

The rule reduces accidental inclusion during ordinary Git discovery/add; forced add can bypass it. It does not remove already tracked/history content, revoke/rotate credentials, encrypt/protect stored files, validate backups/restore or exclude backups from arbitrary packaging/deployment. It covers only the root portable-backups directory, not renamed/copied/nested backups elsewhere. Existing demo accounts and the separate recovery --apply writer remain outside this task; R09 stays partly addressed. Real ownership/platform approval, customer database/private-file restore and maintenance fencing remain open; Phase 3 remains IN PROGRESS.

## IMPLEMENTED — P3-018 interrupted-reset rollback (2026-10-21)

Terminating only the identity-verified owned reset worker after its completed uncommitted password/remember UPDATE and before token deletion caused complete database row rollback. Its MySQL thread disappeared and the full prior snapshot was restored, including password/remember/reset/device rows; the original reset token then succeeded on a fresh retry worker. Bounded polling accounted for rollback visibility after thread teardown. This is synthetic transaction rollback evidence, not customer database/private-file restoration or power-loss durability. [Scope](MYSQL_RECOVERY_REHEARSAL.md).

## IMPLEMENTED — P3-019 recovery apply restriction (2026-10-21)

Legacy recovery --apply now refuses outside exact effective local/testing environments before input or DB/hash work. Invented dry-run inputs and zero-work refusal cases passed; no actual successful apply, customer input, backup integrity or restoration was tested. The original allowed writer still creates known-password accounts and overwrites fixed event ID1; it is not a production provisioning/recovery procedure. [Scope](RECOVERY_SCRIPT_SAFETY.md).

## IMPLEMENTED — P3-020 recovery input shape (2026-10-21)

Recovery CLI options now require nonempty single string paths, two CSV files and a QR directory; malformed shapes return usage exit1 before reading. This preserves the environment guard and valid synthetic dry runs, but establishes no backup authenticity, file readability or successful apply/restore. [Scope](RECOVERY_SCRIPT_SAFETY.md).
