# cPanel deployment

## CURRENT — repository evidence

Local CLI PHP 8.2.12 supports GD, ZIP, OpenSSL, PDO MySQL/SQLite and mbstring; sodium is absent from the inspected CLI extension list. Installed Laravel is 12.70.0 after P2-005. Local settings use file sessions/cache, synchronous queue and log mail; local debug is enabled. These are development settings, not production facts. The configured MySQL connection failed during read-only audit.

Root README describes Laravel `public/` document root, HTTPS, storage link, asset builds and environment setup. `scripts/https-proxy.mjs` and local certificate script are development aids, not verified cPanel infrastructure. `routes/console.php` has only the default inspire command, with no application scheduler. `public/storage` link is absent in the inspected checkout. No cPanel account/configuration or production credentials were inspected.

## TARGET / PLANNED — deployment checklist

1. Confirm hosting PHP/extension/database versions against the locked dependencies. Confirm OpenSSL/signature support, limits for offline crypto/uploads and maximum request/memory budgets.
2. Put the domain document root at Laravel `public/`; keep `.env`, private storage, database dumps and portable backups outside web access. Enforce real HTTPS and secure session cookies. Configure trusted proxies for actual hosting, not arbitrary forwarded headers.
3. Use production environment/debug settings, strong secrets and restricted DB credentials. Preserve APP_KEY/signing keys needed to decrypt existing data; back them up securely. Never regenerate APP_KEY as a routine upgrade.
4. Deploy locked dependencies and built assets. Verify filesystem permissions and storage link strategy; private scanner files must not be symlinked publicly. Keep guest invitation/QR access policy explicit.
5. Rehearse backup/restore and additive migrations before maintenance rollout. Compare row counts/invariants after migration; do not seed demo accounts/data into production.
6. Verify a dedicated safe SMTP/sendmail/provider mailer and trusted APP_URL reset-link HTTPS host before enabling recovery. The P2-003 recovery contract refuses log transport and composite failover/roundrobin mailers to avoid exposing reset links in framework logs. Log mail is not email delivery. Verify after-response flushing/delivery on actual hosting; deferred execution does not guarantee durable delivery if the process dies.
   Communicate one-time reauthentication for legacy browser sessions without a password marker. Configure reset-token path/query redaction in web-server, proxy and access logs; application logger sanitation and no-referrer headers do not sanitize infrastructure logs. Verify MySQL simultaneous reset behavior on an isolated database.
7. P2-004 requires the personal_access_tokens migration, verified HTTPS/trusted proxies and protected client storage. Tokens expire after24hours; verify bounded cleanup (for example sanctum:prune-expired) on a supported schedule. Device labels are not hardware attestation. P2-005 cleared the dated local dependency audit; rerun the locked audit and real-host platform checks before release.
8. Confirm cron availability and CLI PHP path. If queues are needed, use bounded `queue:work --stop-when-empty` execution with overlap prevention and supported cron, or another verified facility. Do not require a permanent worker on unverified shared hosting.
9. Verify signed Meta callbacks and outbound network access when enabled. Check health, role/tenant isolation, scanner camera, export/download permissions, logs, backups and failure recovery.
10. P4-047 private QR storage (PLANNED release gate): source now reads and writes ordinary guest QR images only through the private `guest_qr` disk at `storage/app/guest-qr-private`, with no public fallback. Before deploying it, follow the gated cutover in the [read/export/media design](LEGACY_READ_EXPORT_MEDIA_DESIGN.md): back up DB plus files, fence writers, create the private root outside every web root, inventory and copy existing public QR files with checksums and collision refusal, deploy the `storage/qr-codes` static denial (the supplied `public/.htaccess` rule covers Apache only; add Nginx/alias/CDN equivalents) and verify old URLs are refused, then reopen.
    - Every component of the root path must be a real directory. The policy compares the resolved path with the configured one, so a release layout that symlinks `storage/` (for example `releases/N/storage -> shared/storage`) must instead point Laravel's storage path at the real shared directory (for example with `LARAVEL_STORAGE_PATH`). Otherwise every QR operation fails closed.
    - The inventory must also report stored keys that the exact-key policy refuses, such as backslashes, dot segments or Windows device names (for example a `con` segment created on a Linux host before this change). QR media, regeneration and deletion for those guests refuse until an operator fixes the keys.
11. P4-047 named indexes (PLANNED release gate): family-3 pages, exports and QR delivery name these indexes so that a locking read cannot scan and hold other Events' rows. Before deploying, confirm each exists on the target database with `SHOW INDEX FROM <table>`: `guests.guests_event_id_index`, `checkins.checkins_event_id_index`, `notification_logs.notification_logs_event_id_channel_status_index` and `qr_codes.qr_codes_guest_id_unique`. The repository migrations create them. A missing index makes those pages fail with a SQL error rather than scan silently. [Engine observations and lock limits](MYSQL_READ_BUNDLE_REHEARSAL.md).
    - Without the root, QR preview/download/ZIP, QR generation/repair, single-guest creation (which always issues a QR), imports with QR generation and deletion of guests that have a QR path fail closed and roll back. Existing public copies are neither served nor deleted by the application. No copy or migration has been run.

## REQUIRES VERIFICATION

Actual domain/root, HTTPS, PHP/web CLI mismatch, MySQL/MariaDB version, disk/quota, symlink support, cron frequency, worker/runtime limits, SMTP delivery, outbound access and backup retention. Deployment commands are plans; none were run on hosting during this audit.

## REQUIRES VERIFICATION — P3-002 expansion rollout

The workspace migration is prepared only in source/isolated tests. Obtain real inventory, restore rehearsal and explicit ownership/platform mapping before rollout. It inserts no owners/memberships/grants; event ownership is nullable, memberships inactive and platform capability false. Existing global admin routes remain legacy role-gated. Keep customer access closed until policy/enforcement gates pass. DDL is non-atomic; review partial failures instead of automatically repairing/dropping schema. A populated expansion refuses down; code rollback should retain its columns/tables. SQLite parent rebuilds require no active transaction; actual MySQL/MariaDB DDL is unverified. New restrictive user memberships need deliberate retention/user-management behavior before those workflows are exposed.

## IMPLEMENTED — P3-004 source/test update (2026-10-18)

P3-004 ledger DDL is repository preparation tested only in isolated SQLite fixtures. Do not infer actual rollout from passing tests; obtain current inventory, legitimate owner/platform approval, verified database/file restore and engine rehearsal before running migrations/backfill. No apply command is available. [Guide](OWNERSHIP_LEDGER.md).

## IMPLEMENTED — P3-006 synthetic MySQL evidence (2026-10-18)

The separate local synthetic MySQL8.4.11 rehearsal passed repository DDL and selected backfill/SQL-restore checks. It does not verify the configured database, cPanel MySQL/MariaDB build, private files, credentials, hosting tasks or maintenance fencing. The first actual migration run failed with MySQL 1553 at 2026_07_23_190000: dropping the last old unique index removed the index supporting os_log_session_fk before its replacement existed. The narrow repair creates the four replacement indexes before dropping their predecessors in both directions; final names/columns/uniqueness are unchanged. Fresh history and empty down/up now pass with FK checks enabled. It does not repair an already partially applied live migration or certify populated rollback after duplicate conflict records exist. [Rehearsal scope and evidence](MYSQL_REHEARSAL.md).

## IMPLEMENTED — P3-015 source safeguard (2026-10-20)

The default seeder refuses outside exact local/testing even with --force and refuses any populated sample target instead of deleting/resetting data. This is not a production bootstrap or credential revocation workflow: verify the intended database and environment independently, review already-installed demo access, and keep the separate recovery --apply writer outside routine deployment. No hosted database was seeded or changed. [Scope and isolated evidence](DEMO_SEEDING.md).

## IMPLEMENTED — P3-017 source-backup exclusion (2026-10-20)

Root portable-backups is now excluded from ordinary Git discovery; no indexed paths were found in this checkout. This is not an archive/deployment exclusion or storage access control. Keep secret-bearing backup bundles outside published artifacts and verify actual hosting packaging/access/restore separately. No backup contents or hosting files were changed. [Scope](BACKUP_AND_ROLLBACK.md).

## IMPLEMENTED — P3-019 recovery source guard (2026-10-21)

The recovery --apply writer now refuses outside exact effective local/testing environments. Do not change an environment label as a deployment/provisioning shortcut; labels do not identify a database or validate owner/restore readiness. Existing account cleanup and real recovery require separate reviewed procedures. No hosted database was changed. [Scope](RECOVERY_SCRIPT_SAFETY.md).
