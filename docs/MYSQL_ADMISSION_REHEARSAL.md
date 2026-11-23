# Synthetic MySQL scanner admission conservation

## IMPLEMENTED — P3-010 (2026-10-19)

Five selected cases passed on a separate MySQL 8.4.11 server using ten independent PHP workers and five fresh synthetic databases, each with 27 repository migrations and 31 tables. Four last-entry pairs (QR/QR, manual/manual, QR/manual and manual/QR) showed actual owned-thread lock waits and precommit invisibility. Each pair committed exactly one admission, rejected the other attempt and ended at used_entries=3, allowed_entries=3, with no overshoot.

The guest counter increase, new Admission quantity and successful Checkin entries all equalled one. Prior historical rows were preserved exactly. QR rejection added a zero-entry Checkin and a verify ScanLog; manual rejection added neither, preserving existing response/audit differences. Every other table/record and immutable guest, manual-code, QR, account and offline-provenance field matched the fixture snapshot.

Terminating only the identity-verified owned PHP holder after its uncommitted guest-counter UPDATE caused MySQL to roll back the counter/status change and leave history unchanged. Its connection thread disappeared, the complete prior committed snapshot returned, and a fresh worker then admitted exactly once.

No application code, migration or test changed. All 341 captured pre-existing paths were compared; changes were limited to task documentation. The unchanged-source P3-008 gate of **795 tests / 12,771 assertions** (127.333681s, no errors/failures/skips) was reused; no new full-suite run is claimed. Five TEMP PHP files passed syntax checks, four PowerShell scripts parsed, all 97 routes registered, and existing read-only command options/exits and 31-table empty-schema inventory smoke passed.

## CURRENT — controlled request paths

Workers invoked the unchanged Laravel HTTP kernel for POST /scanner/admit (QR JSON) and POST /scanner/manual-admit (browser redirect). Two distinct persisted active scanner accounts were authenticated through the test session guard. The process-local testing environment used array sessions/cache/mail, null WhatsApp and private temporary storage. No external HTTP listener, message or configured database was used. Testing-mode CSRF/session setup is a fixture, not proof of real browser login, HTTPS deployment or device behavior.

Each holder paused after the authoritative guest used_entries UPDATE, inside the real admission transaction and before history inserts. The coordinator observed the second worker waiting through the owned server performance_schema thread/lock tables, checked the unchanged committed snapshot from a separate connection, and released the holder. The QR winner returned 200/admitted; the QR loser returned 409/already_used. Manual winner/loser returned the existing 302 redirects with success/error session feedback.

| Pair | Accepted | Rejected | New Admission rows | New Checkin / ScanLog rows each |
|---|---|---|---|---|
| QR then QR | First QR | Second QR | 1 | 2 |
| Manual then manual | First manual | Second manual | 1 | 1 |
| QR then manual | QR | Manual | 1 | 1 |
| Manual then QR | Manual | QR | 1 | 2 |
| Holder disconnect, fresh QR | Fresh QR | Interrupted transaction never committed | 1 | 1 |

Snapshots cover all committed rows in all 31 fixture tables. Only guest used_entries/status/updated_at and the expected new Admission/Checkin/ScanLog rows were permitted to differ. Existing manual code 0042, Unicode guest fields, QR token and all offline provenance remained intact. AUTO_INCREMENT gaps after rollback are allowed and are not committed-row corruption.

## CURRENT — isolation and lifecycle

The private server bound only to 127.0.0.1:43490 with MySQLX disabled and no Windows service. Its UUID, data directory, version and session strict SQL/utf8mb4/UTC/FK settings were checked before fixture writes. Oracle Authenticode signature and the existing distribution archive SHA-256 were verified. Generated application credentials had grants only on the five exact fixture databases; unscoped CREATE DATABASE was rejected. All owned workers/server stopped, the port was free, unavailable connection returned safe 2002, and four generated plaintext credential files were removed.

Only task-created PHP processes were eligible for termination after matching executable, absolute worker script, recorded PID, worker name and task nonce. All waits were bounded and outer finally cleanup guarded both normal and failed execution. The private administrative connection was used for owned-server identity and thread/lock observations; request workers used the scoped application account. The successful five-case run required no application repair.

Local TEMP evidence codex-harusi-p3010-20261019 contains admission.json, lifecycle.json, unavailable.json, verification.json and sanitized worker reports. Raw synthetic fixtures/data remain private; generated plaintext credentials were removed. These temporary scripts are rehearsal artifacts, not deployment or production scanner commands.

## REQUIRES VERIFICATION — remaining boundaries

These are selected existing-route transaction cases on synthetic data, not exhaustive concurrency, hosted-engine, throughput, browser/camera/device, offline interoperability or customer tenant-isolation proof. Online admission still has no request idempotency key: retrying a lost successful response can consume more allowance while capacity remains. Existing event-row serialization is unchanged. Real ownership/platform approval, customer database/private-file restore, maintenance fencing and runtime tenant policies remain open; Phase 3 remains IN PROGRESS.

R11 now has bounded actual MySQL last-entry evidence for the selected pairs. R07 request-idempotency and R16 real-environment gaps remain open. See [scanner behavior](SCANNER_ARCHITECTURE.md), [test strategy](TEST_STRATEGY.md), [risk register](RISK_REGISTER.md), [backup rules](BACKUP_AND_ROLLBACK.md), [earlier backfill concurrency](MYSQL_CONCURRENCY_REHEARSAL.md) and [phase contract](phases/PHASE_03_WORKSPACES.md).
