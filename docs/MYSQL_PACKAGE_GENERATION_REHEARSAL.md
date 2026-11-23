# Synthetic MySQL package-generation checks

## VERIFIED — P4-012 (2026-10-23)

All **six actual MySQL 8.4.11 case groups / nine independent service workers** passed, including twelve serial observations and four exact row waits. Six fresh synthetic schemas each applied the frozen 28 repository migrations and contained 32 tables. The P4-011 **1,372 tests / 23,870 assertions** source gate is retained by exact application/test fingerprints; no new backend suite run is claimed for this TEMP/docs-only task.

| Case group | Actual engine evidence |
|---|---|
| Serial eligibility | Twelve observations cover eligible actor membership, active/inactive scanner memberships, noncanonical platform/active flags, byte-exact roles despite actual case/padding collation equivalence, distinct safe PHP-max/unsigned-overflow rows and owned Event refusal. Each guard call performs zero DML and conserves every row, table definition and file. Overflow scanner/actor inputs refuse before row queries. |
| Ownership first | Generation waits on Event PRIMARY 301. Transfer commits, generation returns 404 with no DML/files; only that Event ownership changes. |
| Actor deactivation first | Generation waits on User PRIMARY 211. Deactivation commits, generation returns 403 with no DML/files; only that actor active flag changes. |
| Membership after repeatable-read absence | A caller establishes an ordinary absence snapshot. An independent writer locks User 221 and commits an inactive owner membership. Generation waits on that User and its current locking membership read refuses while the caller's ordinary snapshot still reports absence. Nested then outer rollback preserves the committed grant and all other rows. |
| Generation before transfer | Production generation pauses after eligibility locks and before effects. Transfer waits on Event PRIMARY 301. Generation commits the exact 12 DML operations, one session, two session users, one package, one guest snapshot and four expected audits; its one encrypted file matches the recorded hash. Transfer then commits. A later retry returns 404 and preserves that setup/file. |
| Driver failure and retry | A real synthetic MySQL SIGNAL returns SQLSTATE 45000 / driver 1644 after the new file exists and before package INSERT. All four completed generation inserts roll back and the failed new file is removed. Retry commits the exact 12 operations and expected rows/file. No claim is made about preserving pre-existing files because the initial manifest is empty. |

## Isolation and lifecycle

The Oracle-signed read-only MySQL 8.4.11 distribution ran from a new ACL-private TEMP data directory on loopback 43496 with MySQLX disabled. UUID, datadir, port, bind address and version were checked before fixture writes. Performance Schema matched both worker connection IDs and the exact WAITING PRIMARY RECORD for each observed wait. Complete row snapshots and table definitions were compared; only AUTO_INCREMENT table options were normalized, so allocator restoration or ID reuse is not claimed. All owned workers/server are stopped, the port is free, unavailable driver 2002 was verified and all four generated plaintext credential files were removed. The configured Harusi database, XAMPP and other services/data were untouched.

The coordinator invokes the production package builder/guard and existing migrations against invented fixtures only. Workers and barriers are task-private and bounded. Mail, notifications and HTTP are faked; storage, cache and logs use TEMP. Signing/encryption keys and credentials are synthetic. No configured environment or key file is copied or changed. Failed verification remains fail-closed with finally-based owned-process cleanup; source is never patched to make a schedule pass.

## Verification and preservation

All 404 captured task-start paths are conserved outside task documentation; one new guide is added. TEMP PHP/PowerShell syntax and formatting, unchanged 100 route method/path pairs, 32-table safe CLI, environment/app key, Git index and local documentation checks passed. The full backend suite was not repeated because application/test source is unchanged.

The signed distribution SHA256 is B83561F885785133B79CFE53F225DFFBDBDEC160EA54B5245CFB30CC7A440FA7. Private TEMP evidence is retained under codex-harusi-p4012-20261023: generation.json, lifecycle.json, unavailable.json, distribution.json, per-worker reports and verification.json. These files describe a local rehearsal, not a reusable production migration or deployment command. The nine worker reports and four row-wait records have exact connection/process matching; no timing-only assertion substitutes for an engine wait.

## Remaining limits

Selected synthetic MySQL evidence does not establish every schedule or actual customer rollout. These checks cover package-generation eligibility against cooperating Event/User writers, including one caller RR snapshot case. Arbitrary external writers, other caller states, guest-writer/snapshot consistency, nested historical references, worker crash/orphan recovery, postcommit response/download metadata/audits and later session/import/conflict/file/public-asset paths remain unverified. Credential/session revocation remains the earlier middleware observation. No source/test/schema/migration/API/UI/account/owner/grant/dependency/build/environment/app-key or real-data change or deployment occurred. Customer event/signup exposure remains closed. Actual database/ownership/restore/fencing and browser/device/hosting/SMTP remain rollout gates; Phase4 remains IN PROGRESS.

[Plan](PROJECT_PLAN.md), [phase contract](phases/PHASE_04_AUTHORIZATION.md), [source guard](LEGACY_OFFLINE_PACKAGE_GENERATION.md), [offline parents](LEGACY_OFFLINE_RESOURCES.md), [staff writer protocol](LEGACY_STAFF_ACCOUNTS.md), [offline workflow](OFFLINE_SYNC.md), [verification strategy](TEST_STRATEGY.md).
