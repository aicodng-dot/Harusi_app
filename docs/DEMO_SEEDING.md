# Local/testing demo seeding

## IMPLEMENTED — P3-015 (2026-10-20)

The default [DatabaseSeeder](../database/seeders/DatabaseSeeder.php) creates disposable samples only when the configured environment is exactly local or testing. That check is the first operation in run(): production, staging, unknown, blank or mis-cased labels refuse with a fixed message before database, transaction or password-hash work. Direct invocation and Artisan --force cannot bypass this check.

In an allowed environment, all six sample-data target tables must be empty: users, guests, qr_codes, admissions, checkins and scan_logs. Checks use the write connection and run inside the same transaction as the sample inserts. A populated target refuses; reruns do not reset users, passwords, guest codes, QR tokens, counters or history. The seeder no longer deletes rows or disables foreign-key enforcement.

Fresh allowed runs preserve the original two demo users, six guests/QR codes and two rows in each sample history table. Known demo passwords remain public local fixtures; this is not production account setup. Model observers may create the default event, and their database writes are included in the transaction.

## Verification

The pre-fix direct-production test reproduced unsafe work on an empty isolated SQLite fixture: 1 test / 7 assertions failed, with 89 SQL statements attempted. After repair, focused checks passed **41 tests / 678 assertions** and the complete backend suite passed **849 tests / 13,574 assertions** (132.606666s), with zero errors/failures/skips. All 21 new seeder cases passed in both runs: exact environment/direct/forced refusal before SQL/transaction/hash work, each populated target, allowed sample compatibility, repeat refusal and injected third-QR failure with full row rollback including the observer-created event, followed by successful retry.

Scoped Pint and both PHP syntax checks passed. The original sample insert body is preserved exactly after removing one indentation level; destructive resets and FK toggles are absent. All 350 captured pre-existing paths were compared; only the default seeder, root README and task docs changed, with one new test file. Existing application/model/schema/routes/scripts, .env/app key, builds and dependencies were preserved. Runtime remains 97 routes with exact documented method/path agreement; read-only CLI options/exits and the 31-table empty-schema inventory smoke passed. Local documentation links and whitespace passed. No configured database migration/seeding, real account change, external message, service, frontend, Android or dependency change occurred.

## Remaining limits

The environment label is trusted configuration, not verified database identity; six empty target tables do not mean the whole database is empty. This establishes one-invocation transaction behavior on SQLite, not concurrent seeding, a global maintenance fence or hosted-engine proof. Existing demo accounts, test factories, private backup artifacts and the separate scripts/recover-wedding-data.php --apply writer remain outside the repair. R09 remains partly addressed. Real ownership/platform approval, customer database/private-file restore and maintenance fencing remain open; Phase 3 remains IN PROGRESS.

P3-015 neither executed nor modified the separate recovery script. P3-019 later added its own exact effective local/testing apply guard. Its allowed local/testing path can still create known-password accounts; neither guard revokes accounts already installed. Real administrator provisioning and credential cleanup need a separate reviewed workflow. Do not use recovery tooling as routine installation or reset instructions.

Tests: [DatabaseSeederSafetyTest](../tests/Feature/DatabaseSeederSafetyTest.php). Related: [setup](../README.md), [risk register](RISK_REGISTER.md), [deployment](DEPLOYMENT_CPANEL.md), [backup/recovery](BACKUP_AND_ROLLBACK.md), [task record](PROJECT_PLAN.md).

## IMPLEMENTED — P3-016 synthetic MySQL follow-up (2026-10-20)

All **16 selected actual MySQL 8.4.11 cases** passed (1774 harness assertions) across two fresh task-owned schemas, each with 27 repository migrations and 31 InnoDB tables. Six production/staging/direct/forced calls across empty and populated states refused before SQL, transaction or hash work. Each of the six populated sample targets refused with complete row/DDL conservation and no seeder DML; an allowed local run preserved the original samples, and repeat refusal preserved all 31 tables.

An injected failure before the third QR insert occurred after positive partial counts in users, guests, QR codes, all three history tables and the observer-created default event. InnoDB rolled back every fixture row, including that event, to the complete pre-call 31-table snapshot; Laravel/PDO transaction state returned to idle and foreign-key enforcement stayed enabled. The same testing path then succeeded after the fault callback was disarmed. SHOW CREATE TABLE definitions matched with only the AUTO_INCREMENT option excluded: sequence gaps after rollback are permitted and no sequence restoration is claimed. Unrelated tables and the separate fresh schema remained unchanged.

This verifies selected single-process seeder cases on the recorded synthetic MySQL build. It does not prove concurrent seeding, a global maintenance fence, power-loss durability, another hosting engine, database identity from APP_ENV, real administrator provisioning or credential revocation. Existing known-password accounts, separate recovery --apply, factories and private backup handling remain outside this repair. No configured/customer database, XAMPP/other project service, ownership/grant, frontend/Android/dependency change or deployment occurred. Real ownership/platform approval, customer database/private-file restoration and maintenance fencing remain open; Phase 3 remains IN PROGRESS. [Scope and lifecycle evidence](MYSQL_DEMO_SEEDING_REHEARSAL.md).

## IMPLEMENTED — P3-019 separate recovery guard (2026-10-21)

The legacy recovery --apply path now has its own exact effective local/testing environment restriction before input/DB/hash work. This is separate from default-seeder target/transaction checks and does not provide production provisioning or verify successful recovery. [Scope and evidence](RECOVERY_SCRIPT_SAFETY.md).
