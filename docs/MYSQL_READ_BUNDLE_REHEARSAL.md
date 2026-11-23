# Synthetic MySQL and MariaDB read bundle observations

## IMPLEMENTED — P4-047 slice 4 (2026-11-09)

All 45 selected cases passed on a private MySQL 8.4.11 server and again on a private MariaDB 10.4.32 server (44 checked outcomes plus one recorded query plan), each with a fresh synthetic database (32 tables after the repository migrations). They cover the family-3 pages, exports and QR delivery in [P4-047](PROJECT_PLAN.md#in-progress---p4-047-slice-4-verification-and-limits-2026-11-09). The data was invented. No configured database, account, file or deployed host was used.

Each server bound only to 127.0.0.1 on its own port (43494 and 43495), with a private TEMP data, log and temporary directory and no Windows service. Before any write, a guard checked the server's port, data directory, version and bind address, and it refused every other server. The MySQL binary's Oracle signature was valid. Both servers were stopped and their data removed afterward. The XAMPP data files kept their earlier timestamps.

## What was observed

| Group | Cases | Result |
|---|---|---|
| A: real HTTP kernel | 15 | All family-3 pages and exports return 200. Every private read carries `lock in share mode`, except the route's own `{guest}` binding, which precedes the attempt and reaches no response. |
| B: caller transaction, RR and RC | 2 | Inside a caller's older repeatable-read snapshot, the page shows the current committed Guest while an ordinary select in the same transaction still shows the old one. |
| C: revocation | 2 | An actor demoted after the caller's snapshot, and one demoted at the root, are refused with 403. |
| D: workspace membership | 3 | A new membership removes a scanner label at the root and under read committed. Under a caller's older repeatable-read snapshot the label stays; see limits. |
| E, F: writers hold pages back | 3 | The server reports a lock wait. The page then shows the committed value, or the old value after a rollback. An Event-first writer holds the page at its first lock. |
| G: a held page | 4 | A second page view proceeds. A Guest writer and a new history row for that Event wait, and the writer proceeds after release. The fourth case only records the plan on a three-row table, where the other Event's row stayed free. |
| G2: two Events of 2,000 Guests each | 4 | While a guests, QR, dashboard or reports page is held, the other Event's Guest and QR rows stay writable and the same Event's Guest row waits. |
| H: scripted admission order, both ways | 2 | No deadlock. Counter and history agree (used 1, two history rows) and the admission commits. |
| M: a held QR delivery | 3 | Page views proceed, another Event's QR row stays lockable, and the delivery's own QR row waits. |
| I: arithmetic conservation | 1 | Dashboard statistics equal the former SQL aggregates on `utf8mb4_unicode_ci`, including case-variant, trailing-space and unknown status and pass strings. |
| J: exact role bytes | 1 | A `SCANNER` case variant is not treated as the exact `scanner` role. |
| K: large page | 5 | See below. |

Largest measured page with 5,002 Guests and 20,001 history rows in one Event:

| Page | MySQL ms | MariaDB ms | Peak memory | Locks held (MySQL) |
|---|---:|---:|---:|---:|
| Reports | 1,201 | 920 | 58 MB | 50,053 |
| Check-ins | 754 | 669 | 42 MB | 40,097 |
| Dashboard | 669 | 444 | 48 MB | 50,053 |
| Guests | 237 | 124 | 34 MB | 50,053 |
| QR list | 125 | 122 | 34 MB | 10,023 |

## What these observations changed

The first run found that the read bundle could hold other Events' rows:

- **QR facts join.** It scanned from `qr_codes` and locked every QR row and joined Guest row. It now reads by exact Guest ID.
- **Plan choice.** With two Events of similar size, a locking read of a non-covering query chose a full primary-key scan, which under repeatable read holds every row it passes. An `EXPLAIN` of the covering `SELECT id` form did not show this. Event-scoped locking reads now name their index.
- **QR delivery.** It held exclusive locks while reading every image. Its unindexed global path check also read every QR row under a lock, and scanner admission locks its QR row, so by inspection a long archive could stop every gate. The former locks were not run on these servers; M observes the corrected behaviour. Delivery now uses shared locks, and the path check is an unlocked primary read.

Naming the indexes also cut the largest check-ins page from 2,619 ms to 754 ms on MySQL.

One harness correction: InnoDB can report a background thread as the owner of a converted implicit lock. Waits are therefore matched by blocking transaction rather than by thread.

## Limits

- These schedules are selected cases, not a proof for all interleavings. H uses a scripted statement order, not the HTTP scanner path. [P3-010](MYSQL_ADMISSION_REHEARSAL.md) covers that path without a concurrent read bundle.
- While a page or export attempt runs, writers to that Event's rows wait and do not fail, unless the attempt outlasts `innodb_lock_wait_timeout`. The largest measured page lasted about 1.2 seconds. Real hosts and larger Events can differ, and no sustained load was measured.
- Scanner and creator labels and the Event catalogue are unlocked label reads. Inside a caller's older repeatable-read snapshot they can be stale, as D and B record. Root requests read them after taking their locks.
- The QR path check is unlocked for the same reason. Under a caller's older snapshot it could miss a just-committed reference. Generated paths are unique, and this affects only damaged or hand-edited rows.
- Named indexes must exist on the target database. A missing index fails family-3 pages with a SQL error instead of silently scanning. [Deployment item 11](DEPLOYMENT_CPANEL.md) checks them before rollout.
- MariaDB 10.4 has no `performance_schema.data_locks`, so its lock counts were not measured. Other MySQL and MariaDB versions were not run.
