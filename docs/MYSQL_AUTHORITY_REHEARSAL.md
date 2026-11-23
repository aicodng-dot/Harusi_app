# Synthetic MySQL authority-reader visibility

## IMPLEMENTED — P3-011 (2026-10-19)

All 34 selected actual MySQL 8.4.11 resolver observations passed using one retained resolver, a primary connection, a separate committed writer and a diagnostic shadow connection. The primary fixture applied 27 repository migrations/31 tables; the shadow fixture used only the three required tables. Native role/platform/membership separation, missing context, committed flag/member/account changes, duplicate/malformed membership state, oversized unsigned membership ID, removed parent/account and strict input/privacy checks passed.

A divergent actual shadow read PDO did not supply authority. A throwing read-PDO closure remained unopened across row and metadata reads (zero opens). The explicit reader worked with an unavailable configured default and preserved that default. Snapshots of all rows and SHOW CREATE TABLE output across both fixtures were identical immediately around every resolver invocation; captured SQL/transaction events showed no resolver writes, locks or transaction control.

Under caller-owned REPEATABLE READ, an established snapshot retained earlier platform/member facts after an independent writer committed revocation. The writer committed while the reader transaction stayed open, without waiting on resolver record locks. After caller rollback, the same resolver/connection observed the revocation. Under READ COMMITTED, the next resolve inside the same caller transaction observed committed account deactivation. Both cases preserved caller transaction level and PDO state. Every deliberate fixture mutation was then restored to the complete original row/schema snapshot.

Application source, migrations and tests were unchanged across all 342 captured baseline paths outside task docs. The current P3-008 full result **795 tests / 12,771 assertions** (127.333681s, zero errors/failures/skips) was reused after preservation checks; no new full-suite run is claimed. Four TEMP PHP files passed syntax checks, three PowerShell scripts parsed, all 97 routes and existing read-only command options/exits and 31-table empty-schema inventory smoke passed. Independent read-only harness review found no blocking issue; no application repair was required.

## CURRENT — controlled visibility and conservation

| Check | Observed behavior |
|---|---|
| Outside a caller transaction | Reused resolver observed committed membership/role/platform/account changes |
| Divergent or unavailable read PDO | Row and metadata reads stayed on the explicit primary; unavailable default was unused |
| Caller REPEATABLE READ | Snapshot retained earlier facts after independent revocation; caller rollback exposed current committed facts |
| Caller READ COMMITTED | Subsequent call observed committed deactivation while retaining caller transaction |
| Diagnostic malformed state | Noncanonical text flags, duplicate memberships, ID beyond PHP_INT_MAX and orphan/missing rows granted no affected fact |
| Every call | Entire fixture rows/schema, default and caller transaction state conserved; zero writes/locks/transaction events |
| Final fixture state | All intentional test mutations restored to the original complete snapshot |

The diagnostic fixture intentionally omits membership uniqueness/FKs to exercise the reader contract without weakening repository migrations. The full-schema fixture retains those constraints. The second PDO is an actual independent MySQL connection; no write was simulated in the resolver or supplied through cached Eloquent models. Captured diagnostics emit only fixed statuses/counts, never raw SQL, identities, credentials or row contents.

## CURRENT — isolation and lifecycle

The new private task server bound to 127.0.0.1:43491, disabled MySQLX and registered no Windows service. UUID/datadir/version and primary strict SQL/utf8mb4/UTC/FK settings were checked before synthetic writes; independent connections used the same verified UUID and exact fixture database. The generated application account was scoped to two exact databases and unscoped CREATE DATABASE was refused. The previously verified Oracle distribution archive hash remained unchanged. The owned server stopped cleanly, the port was free, unavailable connection returned safe 2002 and all four generated plaintext credential files were removed.

The harness used process-local testing/storage/config overrides, array mail/cache/session and null WhatsApp. It did not connect to the configured customer database or alter .env/app key, builds, dependencies, XAMPP or other project services/data. Outer finally guarded owned-server shutdown on failure and success. Private TEMP codex-harusi-p3011-20261019 retains authority.json, lifecycle.json, unavailable.json, verification.json and synthetic data; generated plaintext credentials were removed. Rehearsal scripts are temporary verification artifacts, not runtime application commands.

## REQUIRES VERIFICATION — enforcement and rollout

This is selected synthetic MySQL visibility evidence, not runtime authorization, an action permission or a revocation fence. An old retained context remains old, and caller transaction isolation controls visible rows. Different queries in a resolver invocation are not a global atomic authority snapshot. Manager action policy, platform support bypass, ownership transfer/last-owner rules and runtime tenant enforcement remain undefined. Real owner/platform approval, customer database/private-file restore, membership activation and maintenance fencing remain open. Phase 3 remains IN PROGRESS.

See [resolver contract](AUTHORITY_CONTEXT.md), [database conventions](DATABASE_CONVENTIONS.md), [test strategy](TEST_STRATEGY.md), [backup rules](BACKUP_AND_ROLLBACK.md), [phase contract](phases/PHASE_03_WORKSPACES.md) and [project evidence](PROJECT_PLAN.md).
