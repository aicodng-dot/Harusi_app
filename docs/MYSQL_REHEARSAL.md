# Disposable MySQL rehearsal

## IMPLEMENTED — P3-006 (2026-10-18)

Full isolated regression passed **656 tests / 6,689 assertions** (165.260393s), with zero errors/failures/skips. The repaired migration passed Pint/syntax. All 97 routes and the 31-table empty-schema read-only CLI smoke passed; baseline/link/whitespace checks passed.

Actual MySQL 8.4.11 Community (Oracle-signed Win64 distribution) applied all 27 repository migrations on a fresh synthetic schema. The fixture passed 108 checks, conserved 39 legacy rows, rejected seven unique/FK violations and verified eight historical offline-index transitions. It proved current-group rollback/earlier-group retention, partial retry and zero-write completed retry, independent-connection row-lock timeout/release, exact-name drift rejection under a case-insensitive SQL collation, and real unsigned plan/workspace IDs above PHP_INT_MAX refusing without accidental target adoption.

## CURRENT — isolation and lifecycle evidence

The installed MySQL 8.4.11 Win64 Community binaries were reused read-only; Authenticode was Valid with Oracle America Inc. identity. The local distribution archive SHA-256 is A492371D687D2BAB088B0062581144A0044B8964BAEFDF4FAA579292B423D25C. No download or installed binary/configuration change occurred.

The server used --no-defaults, a fresh private task-owned TEMP data/log/tmp/PID directory, bind-address=127.0.0.1, separate port43484, MySQL X disabled and no Windows service. Generated credentials existed only in restricted temporary files, never source/command-line arguments/stdout. The application account had privileges only on the two generated fixture schemas; an unscoped CREATE DATABASE was rejected with1044. Server UUID, data directory, bind address and port were verified before Laravel writes.

Laravel used process-local testing/config-cache/storage/default-connection overrides, array mail/cache/session and null WhatsApp. No .env or application-key change was needed. The connection verified InnoDB prerequisites, foreign_key_checks=1, utf8mb4/utf8mb4_unicode_ci, strict SQL and UTC+00:00. Only synthetic users/events/guest/QR/admission/offline records were inserted; existing databases and portable backups were not opened or altered.

The same server identity and every fixture row survived restart. A scoped-account mysqldump/mysql restore into the second fresh owned schema matched all rows across 31 tables and passed the backfill schema guard. After clean shutdown, the connection failed safely with driver code 2002/exit 2; port 43484 was free and task-created plaintext profile/client/bootstrap credentials were removed.

## IMPLEMENTED — historical migration repair

The first actual migration run failed with MySQL 1553 at 2026_07_23_190000: dropping the last old unique index removed the index supporting os_log_session_fk before its replacement existed. The narrow repair creates the four replacement indexes before dropping their predecessors in both directions; final names/columns/uniqueness are unchanged. Fresh history and empty down/up now pass with FK checks enabled. It does not repair an already partially applied live migration or certify populated rollback after duplicate conflict records exist.

See the [migration source](../database/migrations/2026_07_23_190000_add_explicit_offline_result_conflict_fields.php). Do not rerun or reverse a partially applied live migration based on this fixture result; inspect its actual DDL/history and rehearse a separate forward repair on a protected copy.

## CURRENT — evidence and practical limits

Local evidence is retained under the task-owned TEMP directory codex-harusi-p3006-20261018: mysql-rehearsal.json, restart.json, restore.json, unavailable.json, full.xml, format/CLI logs and baseline/verification.json. Temporary runner scripts and synthetic SQL/data remain private local artifacts, not repository tooling or supported deployment commands. The first restore guard attempt incorrectly enumerated both visible fixture schemas; explicit schema-qualified enumeration fixed the runner before successful import/conservation checks. No application restore code changed.

This is bounded synthetic MySQL 8.4.11 evidence. Configured/customer database identity/schema/inventory, representative customer database and private-file restoration, legitimate owners/platform approval, maintenance fencing, comprehensive races/deadlocks and MariaDB remain unverified. No configured database, XAMPP, other project data/service, runtime writer, grant, frontend/dependency change or deployment occurred. Phase 3 remains IN PROGRESS.

Independent selected-row lock exclusion is not simultaneous backfill/crash/deadlock or unrelated-event-writer fencing proof. Synthetic SQL restoration does not verify customer files, APP_KEY dependencies, device logs or recovery objectives. All four real rollout gates in diagnostic/service reports intentionally remain unverified with rollout_ready=false. Follow the [backfill boundaries](WORKSPACE_BACKFILL_PREPARATION.md), [backup rules](BACKUP_AND_ROLLBACK.md), [phase contract](phases/PHASE_03_WORKSPACES.md) and [task evidence](PROJECT_PLAN.md).

## IMPLEMENTED — P3-009 selected MySQL worker evidence (2026-10-19)

P3-009 adds separate selected actual service contention/disconnect cases beyond P3-006 selected-row lock checks. Existing-plan workers produced one created workspace and a waiting verifier with zero DML. The fresh missing-plan race produced one successful creator and one sanitized engine conflict; exact retry wrote nothing and no duplicate target committed. Competing single-group proposals produced one exact winner and an observed MySQL1213 deadlock sanitized as database_unavailable; the losing retry refused ledger_conflict with zero DML, reassignment or name adoption, retaining one empty losing plan. Terminating only the verified owned PHP worker during group2 caused InnoDB to discard the entire unfinished group while preserving group1; inspector partial, remaining-group resume and zero-DML exact retry all passed. This verifies selected service interleavings and a worker disconnect on the recorded synthetic MySQL build, not complete crash/durability/deadlock/race coverage, another hosting engine, maintenance fencing, customer backup/file restoration or legitimate owner/platform approval. Competing multi-group proposals may retain different committed groups; no single complete winning proposal is guaranteed in general. No automatic retry/global mutex, runtime writer, platform grant, membership activation, XAMPP/configured database change or deployment occurred. Phase3 remains IN PROGRESS. [Scope and evidence](MYSQL_CONCURRENCY_REHEARSAL.md).
