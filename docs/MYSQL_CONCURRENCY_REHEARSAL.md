# Synthetic MySQL concurrency and worker recovery

## IMPLEMENTED — P3-009 (2026-10-19)

All four bounded actual MySQL8.4.11 cases passed with11 separate PHP workers across four fresh synthetic schemas, each applying27 repository migrations/31 tables. Actual owned-thread lock waits, commit/retry outcomes and full legacy-row conservation were verified. Five TEMP PHP files passed syntax checks and three PowerShell scripts parsed successfully. All97 routes, unchanged read-only CLI options/exits and31-table empty-schema inventory smoke passed; baseline/links passed.

No application code, migrations or tests changed in P3-009. The current P3-008 full result **795 tests / 12,771 assertions** (127.333681s, zero errors/failures/skips) was reused only after all340 captured pre-existing source/config/build/dependency paths were checked unchanged outside task docs. No new full-suite run is claimed for this TEMP-only rehearsal.

## CURRENT — cases and observed outcomes

Existing-plan workers produced one created workspace and a waiting verifier with zero DML. The fresh missing-plan race produced one successful creator and one sanitized engine conflict; exact retry wrote nothing and no duplicate target committed. Competing single-group proposals produced one exact winner and an observed MySQL1213 deadlock sanitized as database_unavailable; the losing retry refused ledger_conflict with zero DML, reassignment or name adoption, retaining one empty losing plan. Terminating only the verified owned PHP worker during group2 caused InnoDB to discard the entire unfinished group while preserving group1; inspector partial, remaining-group resume and zero-DML exact retry all passed.

| Case | Deterministic control and acceptance |
|---|---|
| Existing private plan | Pause workerA after its group transaction locks the plan; observe workerB waiting via own-server performance_schema threads/data_lock_waits, releaseA, require create once/verify once and zero waiting-worker DML |
| Missing private plan | Both independent transactions complete the missing-plan lock read before release; allow a sanitized deadlock/duplicate failure, then verify exact same-proposal retry and unique committed target |
| Competing single-group proposals | Different references with the same name/events and pre-existing plans; pause the holder during workspace DML, observe real contention, allow safe engine refusal, require one exact winner and losing-retry refusal without adoption/reassignment |
| Worker disconnect | Pause after group2 mapping DML before commit; record visible group1 state, terminate only the recorded PHP executable/script/name/nonce/PID, observe its MySQL thread close, require exact committed snapshot preservation, partial inspection, resume and zero-DML completed retry |

AUTO_INCREMENT gaps from rollback/disconnect are allowed; comparison covers committed identities/rows, not allocator counters. The private statement observer records only allowlisted1062/1205/1213 codes before the service discards original exceptions; no SQL/bindings/IDs/proposal values are emitted. A source service engine conflict is not a complete-plan rollback or permission to retry a changed proposal.

## CURRENT — isolation and lifecycle

The separate loopback127.0.0.1:43489 server used a fresh private TEMP data/log/tmp/PID directory, --no-defaults, MySQLX disabled and no Windows service. Oracle signature/version and unchanged distribution archive hash were verified. Generated application credentials were scoped to four exact fixture schemas; unscoped CREATE DATABASE was rejected. UUID/datadir/bind/port and strict SQL/utf8mb4/UTC/FK settings were checked before fixture writes. All owned workers and the server stopped cleanly; port43489 was free, unavailable connection returned safe2002 and all four generated plaintext credential files were removed.

The task reused installed MySQL8.4.11 Win64 Community binaries read-only. The local archive SHA-256 remains A492371D687D2BAB088B0062581144A0044B8964BAEFDF4FAA579292B423D25C. Application .env/app key, other project binaries/data, existing services, builds and dependencies were preserved. Coordinator/workers used process-local testing/storage/config overrides, array mail/cache/session and null WhatsApp; only synthetic legacy rows were inserted.

The private admin was used for owned-server lock/thread counts only; workers used the scoped application account. File barriers and bounded waits controlled specific interleavings. Outer finally cleanup ran on every failed/successful attempt. Only owned process identities were eligible for termination; unrelated processes were preserved.

## CURRENT — harness corrections and evidence

Initial attempts stopped safely when the coordinator interpreted the worker early-registration connection_id0 as a completed DB identity. The holder transaction and actual locks were intact; the readiness check now waits for a positive connection identity. A later assertion expected only ledger_conflict for the competing loser; actual InnoDB returned a sanitized1213 deadlock. The TEMP harness now accepts that documented safe engine outcome and requires the eventual losing retry to refuse without writes. Neither correction changed application source or retry semantics.

Local task TEMP evidence codex-harusi-p3009-20261019 retains concurrency.json, lifecycle.json, unavailable.json, verification.json, syntax/CLI artifacts and private synthetic worker reports/data. Generated plaintext profile/client/bootstrap credential files were removed after clean shutdown. TEMP scripts are rehearsal artifacts, not deployment commands or a reachable application writer.

## REQUIRES VERIFICATION — rollout limits

This verifies selected service interleavings and a worker disconnect on the recorded synthetic MySQL build, not complete crash/durability/deadlock/race coverage, another hosting engine, maintenance fencing, customer backup/file restoration or legitimate owner/platform approval. Competing multi-group proposals may retain different committed groups; no single complete winning proposal is guaranteed in general. No automatic retry/global mutex, runtime writer, platform grant, membership activation, XAMPP/configured database change or deployment occurred. Phase3 remains IN PROGRESS. Existing last-entry admission concurrency (R11) is a separate path and is not established by these backfill checks. Follow [backfill boundaries](WORKSPACE_BACKFILL_PREPARATION.md), [progress inspection](BACKFILL_PROGRESS_INSPECTION.md), [earlier DDL/SQL-restore rehearsal](MYSQL_REHEARSAL.md), [backup rules](BACKUP_AND_ROLLBACK.md) and [phase contract](phases/PHASE_03_WORKSPACES.md).
