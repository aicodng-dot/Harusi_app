# Legacy single/bulk Guest deletion

## UPDATE - P4-047 slice 2 storage coupling (2026-11-08, in progress)

P4-047 slice 2 changes only the storage this family deletes from. LegacyGuestDeletion now resolves the fixed private `guest_qr` disk through [GuestQrStorage](../app/Services/GuestQrStorage.php) instead of the public disk. It still does so once, only when bytes are needed and only after every target/reference check. A truthy stored key that fails the exact-key policy refuses the whole operation before vetoes or adapter resolution. Old public copies are not deletion targets. Until the private root exists, deleting a Guest that has a QR path fails closed with the existing feedback, while Guests without a path still delete. All other P4-043 effects and limits below are unchanged. [Slice 2 details](PROJECT_PLAN.md#in-progress---p4-047-slice-2-verification-and-limits-2026-11-08).

## CURRENT - P4-043 (2026-11-03)

P4-043 is COMPLETE in source/tests (2026-11-03) for both existing legacy single/bulk Guest deletion actions. A dedicated one-attempt service validates the entire current Event/admin/offline/Guest/QR/path set and all global shared-path vetoes before resolving QR storage (public at P4-043; the private `guest_qr` disk since P4-047 slice 2) or performing deletion effects. Strict false/cancel failures now produce sanitized account-language feedback. Known SQL rollback cannot restore removed bytes; caller transactions, arbitrary callbacks, file provenance and unknown commits retain their explicit limits. Full isolated gate: **2,166 tests / 43,324 assertions**. Customer writers/signup, actual rollout and all 39 approved UI implementations remain gated. [Deletion contract and limits](LEGACY_GUEST_DELETION.md).

This source contract implements the [P4-042 reviewed design](LEGACY_GUEST_DELETION_DESIGN.md) for [AdminController](../app/Http/Controllers/AdminController.php)::destroy and bulkDestroy. The controller's old deleteGuestPass helper is removed. The [dedicated service](../app/Services/Tenancy/LegacyGuestDeletion.php) owns all deletion effects; [LegacyGuestWrites](LEGACY_GUEST_WRITES.md) and [Guest/QR metadata](LEGACY_GUEST_QR_WRITES.md) retain their separate three-attempt metadata composition.

## One captured operation, one attempt

LegacyGuestDeletion::delete(Guest, User, mixed selectedEventId): void and deleteMany(array guestIds, User, mixed selectedEventId): int freeze canonical original/current Guest/Event/actor identities before guard SQL. Bulk accepts 1-50 distinct strict positive PHP-range integer/decimal-string IDs, then sorts using exact integer comparison, including adjacent PHP_INT_MAX values. No cached model collection, request/session retargeting, inferred owner or caller QR/path authorizes a later effect.

Exactly one default-connection transaction attempt encloses the complete target set. Primary locking reads require the current selected Event to remain unowned, the current actor to be exact active admin, the unchanged offline Event reread/blocking-Session checks, all requested Event-scoped Guests in ascending PK order, and their current unique QR associations in QR-PK order. Exact counts and canonical raw parents/IDs must agree. Ordinary/platform/workspace-linked administrators satisfy the same predicates without a bypass; existing historical Event eligibility stays compatible.

Every exact nullable raw QR path is captured before deletion callbacks. Unsupported raw types refuse; no QR, null, empty string or literal '0' retains the legacy no-byte branch. For every truthy path, a primary locking ID-only query vetoes any other QR with that path, excluding only the current QR PK. No Event/Guest/disk/selected-set filter hides another reference: two selected Guests sharing one path refuse too. Queries are bounded by 50 targets; absent path indexing means database scans/lock breadth are not claimed bounded. No path rewrite, unique index or provenance ledger is introduced.

All target/reference checks finish before QR storage is resolved. The fixed disk (public at P4-043; the private `guest_qr` disk since P4-047 slice 2) is resolved once only when bytes are needed, so denied requests do not construct a potentially directory-creating local adapter. A positive veto prevents the entire operation before storage/deletion DML. Absence of another QR reference is not exclusive file ownership or protection from aliases, unmodelled references or concurrent/external replacement.

## Effects and responses

The frozen schedule visits Guests in ascending ID order. Each truthy path gets one direct delete call, no exists/read/retry; strict true is required. Then the guarded current Guest receives one ordinary model delete, also requiring strict true. Model events are preserved; no bulk query deletion, quiet deletion, receipt or history scan replaces them. The bulk return is the captured successful target count, not a SQL affected-row receipt.

| Outcome | Named behavior |
|---|---|
| Current authority/target/reference refusal | No newly authorized bytes/deletion DML. Actor 403, hidden context/lineage 404, existing offline browser/JSON feedback; ambiguous/type failures use fixed operational feedback. |
| Missing QR/falsey path or missing-file adapter success | Preserve ordinary Guest deletion and existing success response; unrelated/orphan bytes remain. |
| Storage false/throw or model false/null | Stop before later targets; fail privately. Known transactional SQL rolls back, while earlier/current byte effects can remain. |
| Actual PDO/SQL exception | Laravel handles the single transaction attempt before sanitized outward failure. Ordinary SQL/FK rollback does not restore removed files. |
| All targets succeed and transaction returns | Existing single redirect and bulk singular/plural success remain exact. |

Storage resolution/deletion and non-SQL model callback failures are replaced with the fixed [LegacyGuestDeletionFailed](../app/Exceptions/LegacyGuestDeletionFailed.php) inside the transaction callback. This prevents a storage/model message containing a concurrency keyword from being mistaken for a SQL deadlock by Laravel's nested transaction handler. Actual PDO exceptions retain framework transaction handling; a nested real SQL deadlock, caller retry/rollback or unknown commit outcome is not generally recovered here. Authority/OfflineEventLockedException behavior remains unchanged.

Both controller actions catch only the fixed operational exception. Its internal text is fixed and has no raw previous cause, private path, SQL or credentials. Browser error uses guest_deletion.failure from [SW](../lang/sw/guest_deletion.php) or [EN](../lang/en/guest_deletion.php), selecting only a supported account preference and otherwise SW. Global locale and existing success messages stay unchanged. Even requests expecting JSON retain the established HTML redirect shape; no new route/API envelope or general visual work is added. The label asks the user to refresh before another action because partial effects are possible.

## Existing FK and admission compatibility

No migration, FK, code/token/capacity/history rewrite or admission calculation changes. Ordinary deletion preserves the repository consequences below; it does not preserve every historical row.

| Existing relation | Deletion consequence |
|---|---|
| QR and Admission Guest FKs | Cascade; corresponding rows disappear. |
| Checkin Guest and QR FKs | Set null; retained Checkin scalar/Event/provenance values stay. |
| ScanLog and NotificationLog Guest FKs | Set null; surviving scalar history stays. |
| Offline conflict Guest/replacement Guest FKs | Set null; scalar history and unrelated parents stay. |
| Package snapshot/final Guest-state FKs | Set null; external IDs, snapshot/version/counter/parent provenance stay. |
| Offline imported logs | Preserve existing external scalar provenance; invent no Guest FK. |

The rich fixture verifies the full declared FK/scalar catalog and unaffected neighbors. Existing Single/Double/Special capacity, QR/manual credentials of survivors, offline formats and admissions remain authoritative. This service creates no second Guest/QR/admission system.

## Verification

Original-source HTTP reproduction: **45 cases / 284 assertions / 31 failures / zero errors**; 14 compatibility cases pass, and 19 direct future-service cases are excluded. These are regression outcomes, not 31 separate vulnerability claims. Boundary **64 tests / 766 assertions**, existing Guest/QR/scanner/offline compatibility **149 tests / 1,823 assertions**, focused **310 tests / 5,442 assertions** and full **2,166 tests / 43,324 assertions** (371.062782s JUnit) pass with zero errors/failures/skips. All **90 prior classes** retain exact case/assertion counts. Tests use actual SQLite :memory:/PDO/autocommit guards, unique TEMP fake public storage and whole-table/file snapshots; no configured database or existing application/customer file is changed.

Only destroy/bulkDestroy, their service imports, removal of deleteGuestPass and the narrow guestDeletionFailure helper change in AdminController; its other 88 methods and remaining bytes are preserved. Five reviewed runtime payloads plus one new independent regression class comprise the source change. All 365 frozen tested-source paths and 410 unrelated non-document baseline paths match, including environment/app key, Git index and builds. Syntax/read-only scoped Pint and unchanged 100 routes/32-table safe CLI pass. The 39 original PNGs, five protected UI documents, 20 previous UX rows, eight reference batches and 39 NOT STARTED screen statuses remain exact. Phase4 remains IN PROGRESS.

The independent regression class covers the reviewed finite matrix: raw identities and exact maximum ordering; late actor/Event/Guest loss before adapter resolution; primary scoped whole-set ordering; changed current QR/path versus cached relations and frozen scalar paths; same/foreign/selected-set shared references; missing/no-byte positives; false/partial throw/cancel; real SQL rollback after observed bytes; actual single SQL attempt and concurrency-shaped adapter/model messages; caller-owned savepoint preservation; offline responses; complete FK/scalar compatibility; and SW/EN/invalid/null preference with unchanged global locale. The P4-042 12/168 probe remains historical old-code characterization, not another main suite.

## Limits and remaining phase work

Application callbacks remain trusted; strict model true is not an affected-row or persisted-delete receipt. No arbitrary callback/trigger/external-writer, alias/hardlink/symlink, foreign-table reference, filesystem provenance or forensic-erasure guarantee is added. An adapter may change bytes before false/throw; later SQL rollback and enclosing caller rollback cannot restore them. Exactly one local attempt does not authorize an outer caller retry or prove an uncertain commit outcome.

SQLite tests show named builder intent and deterministic rollback/effects, not deployed MySQL lock waits, collation/path-index behavior or storage durability. Installed real constraints, legitimate ownership/platform approval, protected-file/DB/key restore, maintenance fencing and intended-host/device/deployment proof remain open. Family 2's bounded source contract is accepted; create/import/generation/repair, reads/exports/static media and scanner authority remain in the [Phase4 exit register](PHASE_04_EXIT_AUDIT.md). Shared extraction stays P5/P13; invitation/RSVP and the original 39-screen simple UI stay in their owning phases. No customer writer/signup is exposed.
