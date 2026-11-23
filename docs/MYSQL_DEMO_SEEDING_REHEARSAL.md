# Synthetic MySQL demo seeding rehearsal

## IMPLEMENTED — P3-016 (2026-10-20)

Objective: verify selected default-seeder refusal, conservation and rollback behavior on a separate disposable actual MySQL engine after the P3-015 source repair.

All **16 selected actual MySQL 8.4.11 cases** passed (1774 harness assertions) across two fresh task-owned schemas, each with 27 repository migrations and 31 InnoDB tables. Six production/staging/direct/forced calls across empty and populated states refused before SQL, transaction or hash work. Each of the six populated sample targets refused with complete row/DDL conservation and no seeder DML; an allowed local run preserved the original samples, and repeat refusal preserved all 31 tables.

An injected failure before the third QR insert occurred after positive partial counts in users, guests, QR codes, all three history tables and the observer-created default event. InnoDB rolled back every fixture row, including that event, to the complete pre-call 31-table snapshot; Laravel/PDO transaction state returned to idle and foreign-key enforcement stayed enabled. The same testing path then succeeded after the fault callback was disarmed. SHOW CREATE TABLE definitions matched with only the AUTO_INCREMENT option excluded: sequence gaps after rollback are permitted and no sequence restoration is claimed. Unrelated tables and the separate fresh schema remained unchanged.

The previously identified Oracle-signed distribution retained its expected archive SHA-256 and version. A new private TEMP datadir/log/tmp/PID/profile bound only to 127.0.0.1:43492, with MySQLX disabled and no Windows service. UUID/datadir/version and strict SQL/utf8mb4/UTC/FK settings were checked before fixture writes. Generated application credentials were scoped to the two exact databases; unscoped CREATE DATABASE was refused. Only the verified owned server was stopped; its port was free, a fresh connection returned safe driver 2002, and all four generated plaintext credential files were removed. No external messages were sent.

All 352 pre-existing baseline paths were compared and new additions checked; application source/tests/models/migrations/scripts, root README, .env/app key, builds and dependencies were preserved. The existing P3-015 **849-test / 13,574-assertion** full gate was reused only after unchanged-source comparison; no new full-suite run is claimed. Three TEMP PHP files passed syntax checks and three PowerShell scripts parsed. All 97 documented route method/path pairs matched runtime registration; read-only CLI options/exits, 31-table empty-schema inventory, documentation links and whitespace passed. Independent read-only harness review found no remaining blocking issue before execution.

## Scope and retained evidence

TEMP-only helpers and safe aggregate results are retained in codex-harusi-p3016-20261020. Generated plaintext profile/client/bootstrap credential files were removed after shutdown. No private identifiers, credentials, raw rows, SQL errors or customer context appear in this report.

Six populated-target fixtures used valid references inside task-owned outer transactions that were rolled back; no fixture reset DELETE was used there. The only harness DELETE removed the verified synthetic migration-created default event before the rollback baseline, to exercise its observer recreation. No seeder deletion or FK-toggle SQL occurred. All six guard existence queries were observed in the seeder transaction; source uses useWritePdo and these connections had no replica.

## Limits

This verifies selected single-process seeder cases on the recorded synthetic MySQL build. It does not prove concurrent seeding, a global maintenance fence, power-loss durability, another hosting engine, database identity from APP_ENV, real administrator provisioning or credential revocation. Existing known-password accounts, separate recovery --apply, factories and private backup handling remain outside this repair. No configured/customer database, XAMPP/other project service, ownership/grant, frontend/Android/dependency change or deployment occurred. Real ownership/platform approval, customer database/private-file restoration and maintenance fencing remain open; Phase 3 remains IN PROGRESS.

Related: [default seeder scope](DEMO_SEEDING.md), [task record](PROJECT_PLAN.md), [phase report](PHASE_03_REPORT.md), [database conventions](DATABASE_CONVENTIONS.md), [backup/recovery](BACKUP_AND_ROLLBACK.md).
