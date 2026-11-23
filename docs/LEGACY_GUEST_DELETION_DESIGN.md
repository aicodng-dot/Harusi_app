# Legacy single/bulk Guest deletion design

## Historical design - P4-042 (2026-11-02)

Historical design and old-source characterization only. P4-043 later implemented this reviewed contract; use the [current deletion source contract](LEGACY_GUEST_DELETION.md) for implemented behavior, checks and limits. References below to current/old implementation and future acceptance describe the P4-042 checkpoint.

This is a proposed contract for one bounded implementation of the existing Guest deletion family. It does not implement a service, change a controller/test/schema or authorize real deletion. The [finite Phase 4 register](PHASE_04_EXIT_AUDIT.md) remains open. P4-040 remains the latest implemented source gate: **2,102 tests / 42,558 assertions**, 360 frozen source paths, 100 route method/path pairs and 32 inventory tables, retained rather than rerun for this design.

Scope is [AdminController](../app/Http/Controllers/AdminController.php)::destroy, bulkDestroy and deleteGuestPass, with existing Guest/QR/offline/storage/history dependencies and one new bilingual failure label. No QR generation, admission algorithm, schema, receipt, new audit, retention rule, visual redesign, UI-flow change or customer exposure belongs in the implementation by default. Other remaining families and later Phase 5 extraction retain their own gates.

## Historical behavior and characterization status

`destroy` performs selected-Event/offline entry checks, then uses a three-attempt transaction. It repeats the offline check and locks an Event-scoped Guest with an eager QR. `bulkDestroy` validates 1–50 distinct integer IDs, checks the selected-Event count and loads the Guest/QR collection **before** its three-attempt transaction. The transaction later deletes that cached collection. `deleteGuestPass` loads a missing QR relation, calls `Storage::disk('public')->delete(path)` for a truthy path, ignores its boolean result, then calls `Guest::delete()` and ignores that result too.

Thus bytes precede Guest SQL. Ordinary SQL failure can roll back rows after bytes are removed; a later bulk failure can undo earlier SQL while leaving earlier files absent. A retry can call delete again. Bulk callbacks reuse Eloquent objects: an earlier successful delete marks its model nonexisting even if later SQL rolls back, so another attempt need not issue identical bulk SQL. False storage or a cancelled model deletion can still reach existing success feedback. Cached bulk parent/QR state and request-entry actor checks do not supply current authority at the byte boundary.

A separate guarded TEMP-only characterization probe passes **12 cases / 168 assertions**, zero errors/failures/skips, in 58.682039 JUnit seconds. Its 12 unique observation cases match the final JUnit: ordinary single/bulk; missing QR/file; ignored storage false; cancelled Guest deletion; single/bulk SQL refusal after bytes; three synthetic lock-shaped **single-delete** SQL attempts; and late bulk parent, QR and actor changes. These are observations of the current implementation, not repair acceptance, a new exploit count or another main-suite run. The probe gate preserves all 360 frozen source paths, 411 unrelated non-document paths, 39 original PNGs and five protected UI references.

The initial fixture run had 12 bootstrap errors and zero assertions because installed Laravel treated its `C:` TEMP cache path as relative. A TEMP-only `createApplication` adjustment registered the `C:` absolute-cache prefix before kernel bootstrap; database/PDO/autocommit and fake-storage guards stayed unchanged. Initial failed source/output and the clean rerun are retained separately. No application repair was made.

## Proposed API and controller composition

Use a dedicated `App\Services\Tenancy\LegacyGuestDeletion`, with injected `OfflineEventLockService`, and no caller-supplied write callback:

```php
public function delete(Guest $guest, User $actor, mixed $selectedEventId): void;
public function deleteMany(array $guestIds, User $actor, mixed $selectedEventId): int;
```

Both delegate to one private captured-set operation; the single method supplies exactly one captured ID. The bulk return is the captured successful target count, not an affected-row receipt. The service owns the byte call and ordinary model deletion. It never calls `LegacyGuestWrites::mutate`/`mutateWithQr`, whose existing three attempts are metadata composition.

Keep both controller signatures, existing entry checks, bulk request validation/messages and all normal success redirects/messages. Pass `request()->user()` and explicit `session('selected_event_id')` once. Bulk passes the **raw validated ID array**, not an integer-mapped collection or loaded models; its old pretransaction model collection/count query moves into the protected current-set read. Do not reread request/session selection inside the transaction. Remove the unused unsafe private controller helper after moving this family's owned behavior into the service; leave other controller methods unchanged.

## Frozen canonical invocation context

Before this service's guard SQL/transaction, use [PositiveIntegerId](../app/Support/PositiveIntegerId.php) rather than permissive casts:

- The single Guest's raw original/current PK and Event must be canonical and equal, and its Event must equal the explicit canonical selected Event. Refuse mismatch with 404.
- Bulk requires an actual 1–50-element array of canonical positive PHP-range int/decimal-string IDs. Reject floats, booleans, null, signs/leading zeros, whitespace, overflow and duplicate canonical identities; do not alias/deduplicate them into another target set. Existing HTTP validation still runs first. Freeze the scalar IDs and sort numerically ascending; direct invalid service context refuses 404 before SQL.
- Freeze the actor's canonical original/current raw identity using the existing safe-identity convention; invalid actor context is 403. No role, platform flag, membership or cached Event/QR relationship is frozen as authority.

The selected Event, actor and ID set are immutable across the sole attempt. PHP_INT_MAX remains a valid identity when supported by the fixture/schema. No default Event, inferred owner, later request retargeting or caller QR ID/path is accepted.

## One attempt and the complete current set

Use exactly **one default-connection transaction attempt for the whole operation**: `transaction(callback, 1)`. There is no outer framework retry, per-Guest retry, fallback call or metadata helper around bytes. With normal HTTP autocommit entry, the operation is one root transaction. A caller-owned transaction may instead supply the surrounding SQL scope; it does not make bytes reversible or authorize an outer caller to retry this invocation.

Before the first storage call or deletion DML, obtain and validate **all** current targets and vetoes:

1. Primary-lock the captured selected Event with `workspace_id IS NULL`; raw canonical PK and null ownership must match. Refuse 404. Preserve historical Event status compatibility; add no active-only or retention policy.
2. Primary-lock the captured User; require exact raw `admin` and known true activity. Refuse 403. Ordinary, platform-flagged and workspace-linked administrators retain these same predicates without a bypass or new membership requirement.
3. Call the unchanged offline service with the current locked Event and `true`. Retain its own Event reread and blocking-Session query and existing browser/JSON lock feedback. Do not claim only one Event read.
4. Primary-lock Guests in ascending PK order with both captured IDs and selected Event in SQL. Require exact count/identity-set equality and canonical raw PK/Event for every row. Missing, moved or aliased targets refuse 404; no silently smaller bulk operation.
5. Primary-lock the current QR rows for that Guest set, ordered by QR PK. Check every raw QR PK and `guest_id`, at most one row per Guest, and membership in the captured Guest set. The existing unique `qr_codes.guest_id` schema is unchanged. Missing QR is allowed. Never reuse a preflight/cached QR or load a fresh parent from a callback-mutated model.
6. Capture operation-local scalar QR IDs, exact raw nullable image paths and the fixed existing `public` disk. A path is nullable string data, never permissively cast, reconstructed from token/name, trimmed or normalized into a different target. Preserve the old no-byte branch for no QR or a falsey schema string (`null`, `''`, or `'0'`). Unsupported raw types refuse safely. No new namespace/retention/provenance policy is inferred; existing adapter path-rejection exceptions fail generically rather than select a fallback.
7. Complete the shared-reference veto below for every byte target before the first unlink. Freeze the internal deletion schedule in ascending Guest ID. Only then execute effects.

These primary locks are a named pre-effect boundary. Application model callbacks remain trusted. Freezing scalar expectations avoids taking later byte paths from callback-mutated models; it is not certification of arbitrary callbacks, external writers or connection manipulation.

## Shared paths: positive references veto, absence is not ownership proof

The [QR schema](../database/migrations/2026_05_14_110000_create_core_qr_admission_tables.php) makes `qr_image_path` nullable but **not unique**. The [unique Guest association](../database/migrations/2026_05_25_150000_add_unique_guest_id_to_qr_codes_table.php) does not prevent different Guests' QRs referencing the same file. Current Guest → QR → raw path lineage therefore does not prove exclusive physical ownership.

For every truthy captured path, run a primary locking **ID-only** query for any other QR row whose `qr_image_path` equals the captured raw path, excluding only that current QR PK. Do not filter by Event, Guest, disk or deletion set; do not hydrate another Event's private model/path/token. A single positive reference refuses the **entire operation before any storage/DML**. Two selected targets sharing a path are deliberately refused too; do not silently deduplicate unlink calls and infer joint ownership. This is a new conservative ambiguous-path refusal.

Order captured paths deterministically for these checks. Use bound parameters, return at most one ID per query and issue at most one such query per target path. The number of queries/results is bounded by 50; database scans, lock breadth and contention are not claimed bounded without an installed path index/engine observation. SQL collation may conservatively veto equivalent spellings; do not normalize a path to avoid a veto.

This protects known positive database references. It is not filesystem provenance, a unique-file ledger or protection from aliases/hard links/symlinks, another table's unmodelled references, arbitrary concurrent reference insertion or external file replacement. No index/schema, path scan, cleanup job or repair is added. Selected engine behavior remains an implementation/rollout check, not a guarantee inferred from SQLite.

## Exact byte → SQL behavior and feedback

After the complete pre-effect validation, visit the frozen Guest schedule. For a truthy captured path, call the fixed disk's `delete(path)` **once** and require the result to be strictly `true`; do not add `exists`, file reads or a second delete. This preserves the existing direct-call shape. Ordinary local/Flysystem missing-file deletion reports success; a different adapter reporting false for a missing file is still a refusal, not permission to invent a separate existence oracle. No QR/no-byte path skips the storage call.

Then call the guarded current Guest's ordinary `delete()` once and require strict `true`. Preserve model events; do not use query-builder bulk deletion, `deleteQuietly` or synthetic history writes. [Eloquent delete](../vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php) returns false for a cancelled `deleting` event and null for a model no longer marked existing; strict true detects those ordinary cancellations. Its true return does **not** expose an affected-row count or certify a persisted deletion against arbitrary callbacks. Add no receipt or post-delete history scan.

| Outcome | Required effect and response |
|---|---|
| Any authority/target/QR/path/reference refusal before effects | No new bytes/deletion DML. Preserve 403/404 and existing offline feedback; ambiguous/path-type failures use the fixed operation error below. |
| No QR/no-byte path; or direct missing-file delete returns true | Proceed with ordinary Guest deletion and existing FK consequences; normal success remains. Do not remove unrelated orphan files. |
| Storage returns false | Do not delete this Guest or attempt later targets. Throw the fixed deletion failure, causing known transactional SQL work to roll back. Earlier bytes may remain absent. This deliberately replaces ignored-false success. |
| Storage throws, including a concurrency-shaped message | Stop immediately, no retry. Adapter effects may already be partial. Fail generically; no claim that throwing preserved bytes. |
| Guest delete returns false/null | Stop and fail, rolling back known SQL work. Its preceding byte call is not undone. This deliberately replaces cancelled-delete success. |
| Guest SQL/model callback throws after earlier effects | Stop; normal transaction rollback restores known SQL/FK changes, while earlier/current removed bytes stay removed. No later target is attempted. |
| Callback/commit failure or lost acknowledgment | One attempt only. Do not automatically repeat unlink or certify unknown SQL outcome. Commit uncertainty remains an explicit limit. |
| Every target completes and transaction returns | Return the captured count; preserve single redirect/success and bulk singular/plural success exactly. |

All-or-refuse **authority/target acceptance** happens before the first effect; normal rollback gives all-or-none SQL for the batch. It does not give all-or-none bytes. Per-Guest byte→SQL order is retained, rather than deleting every file before any Guest SQL. Bulk effect order becomes explicitly ascending Guest ID, replacing incidental preloaded-query order.

Introduce a small `LegacyGuestDeletionFailed` operational exception for new false/cancel/ambiguous-path refusals and sanitized storage/model/SQL execution failures. Its internal message is fixed (`Guest deletion failed.`), independent of locale; attach, expose, log or report no raw cause diagnostics. Preserve HTTP authority exceptions and `OfflineEventLockedException` unchanged. Both actions catch only that fixed operational failure and return `back()->with('error', __('guest_deletion.failure', [], $language))`. Their existing redirect response type remains, including requests expecting JSON; no new API contract is added.

The new browser label uses narrow Laravel `lang/sw/guest_deletion.php` and `lang/en/guest_deletion.php` catalogs, each keyed `failure`:

| Locale | Proposed `guest_deletion.failure` label |
|---|---|
| sw | Haikuwezekana kukamilisha ufutaji. Pakia upya orodha ya wageni kabla ya kuchukua hatua nyingine. |
| en | Guest deletion could not be completed. Refresh the guest list before taking another action. |

Select `$language` from the account's `preferred_language` only when it is in `User::SUPPORTED_LANGUAGES` using strict comparison; otherwise use `sw`. Follow the existing explicit-locale pattern in [CustomerWorkspaceController](../app/Http/Controllers/CustomerWorkspaceController.php) and its [SW](../lang/sw/customer.php)/[EN](../lang/en/customer.php) resources. A small controller language helper may share this rule between the two catches. Do not change global `APP_LOCALE`, existing legacy success strings, other feedback or layouts. This narrow label requirement does not start Phase 6 visual work.

This error feedback is an intentional change from false/cancel success and unhandled operational failure, justified because the server must not certify a deletion that the adapter/model refused and must not reveal private diagnostics. It avoids claiming nothing changed or instructing an automatic retry after partial bytes. Do not log/report raw private exceptions or create a new audit as part of this family.

## Admission/history and schema compatibility

Ordinary deletion of an admitted/cancelled/partial/full Guest remains eligible under the same legacy/offline rules; no new retention or admission veto is introduced. Preserve the current FK behavior rather than saying all history survives:

| Existing relation | Existing repository deletion consequence to retain |
|---|---|
| `qr_codes.guest_id`, `admissions.guest_id` | Cascade; the QR row and that Guest's Admission rows are removed. |
| `checkins.guest_id`, `checkins.qr_code_id` | Set null; surviving Checkin scalar/event/provenance data stays unchanged. |
| `scan_logs.guest_id`, `notification_logs.guest_id` | Set null; surviving scalar history stays unchanged. |
| Offline conflicts' `guest_id` and `replacement_guest_id` | Set null; conflict/history scalars and unrelated parents remain. |
| Package Guest snapshots and final Guest states' `guest_id` | Set null; external IDs, snapshots, versions, counters and parent provenance remain. |
| Offline imported logs | Their external Guest identity is scalar provenance, not an ordinary Guest FK to invent or rewrite. |

Sources: [Admission](../database/migrations/2026_05_14_090001_create_admissions_table.php), [QR/Checkin](../database/migrations/2026_05_14_110000_create_core_qr_admission_tables.php), [ScanLog](../database/migrations/2026_05_14_090002_create_scan_logs_table.php), [NotificationLog](../database/migrations/2026_06_02_130000_create_notification_logs_table.php), [offline support](../database/migrations/2026_07_23_140001_create_offline_scanner_support_tables.php), [snapshots](../database/migrations/2026_07_23_180000_add_offline_scanner_importer_provenance.php), [final states](../database/migrations/2026_07_23_160000_add_android_master_results_schema.php) and [replacement relation](../database/migrations/2026_07_23_190000_add_explicit_offline_result_conflict_fields.php). Installed real schema remains unverified by this source inspection. Do not change migrations/FKs, surviving counters/history, neighbor data, manual codes/tokens of surviving Guests, offline formats or admission calculations.

## Finite implementation acceptance matrix

Actual future test counts remain pending implementation; the following groups define scope, not invented passing evidence.

| Group | Required implementation observations |
|---|---|
| 1. Frozen raw context | Single original/current PK/Event and actor IDs; bulk canonical set/duplicates/limits; selected mismatch and malformed inputs refuse before guard SQL; PHP_INT_MAX positive. |
| 2. Complete current authority | Late actor role/activity, Event ownership and any Guest move/missing row refuse before all storage/DML; valid ordinary/platform/member and historical Event controls. |
| 3. Current set/order | Primary Event → actor → unchanged offline reads → ascending scoped Guests → ascending current QRs → ID-only global path vetoes; count/raw checks and no stale cached bulk models. |
| 4. Association/path movement | Replace QR/path after entry; use current unique association and frozen current raw path, never cached neighbor/orphan bytes or mutable later request data. |
| 5. Shared references | Same-Event, foreign-Event and two-target same-path references veto the whole batch before first storage; only ID projected; no private neighbor hydration. |
| 6. Ordinary/missing positives | Single/bulk, missing QR, null/empty/falsey path and direct missing-file success; orphan/neighbor bytes unchanged; exact original success feedback. |
| 7. False/throw bytes | Strict false and pre/partial adapter throws stop before this Guest SQL/later targets; generic feedback and named earlier-byte/SQL outcomes. |
| 8. Model cancellation | `Guest::delete()` returns false for cancelled `deleting` or null for a model no longer marked existing: fail after preceding bytes, with no false success; earlier SQL rollback and surviving history effects exact. A listener returning null normally continues. Strict true is not asserted to be a receipt. |
| 9. Real SQL rollback | Single DELETE refusal and later bulk DELETE failure after an earlier successful deletion; SQL/FKs roll back while observed removed bytes remain absent. |
| 10. Exactly one attempt | Actual PDO/SQL concurrency-shaped failure and storage/model exception each observe one callback/deletion attempt, zero automatic repeat unlink and no fallback. |
| 11. Cascade/scalar compatibility | Admitted/history-rich fixtures exercise the full FK catalog above; deleted Admission/QR versus retained null-FK scalar history distinguished; all neighbors conserved. |
| 12. Boundary/feedback controls | SW/EN failure labels selected by supported account preference, invalid/null preference falling back to SW, unchanged normal legacy success strings and global locale; late offline browser/JSON feedback; explicit caller transaction rollback; no route/schema/audit/generation effects or raw cause diagnostics. Unknown-commit controls describe uncertainty rather than assert recovery. |

Use an independent guarded SQLite :memory:/actual-PDO/normal-autocommit fixture and unique TEMP fake public storage for source regressions, with whole-table and byte snapshots. Reproduce relevant original HTTP behavior before installing a later implementation; do not weaken old cases. Run relevant compatibility/focused/full gates only for that source change, preserving P4-039/P4-040 metadata and other families. Add selected synthetic MySQL lock/reference-query observations where the proposed order requires them; do not present SQLite as actual lock-wait proof.

## Implementation boundary and remaining limits

Expected later source scope: one dedicated service, its small fixed operational exception, the two controller methods/imports and removal of their now-unused deletion helper, a narrow controller language helper if needed, `lang/sw/guest_deletion.php`, `lang/en/guest_deletion.php`, one independent regression class and affected docs. The two catalogs contain only the new failure key; no broader localization or visual work is implied. No implementation is authorized by this design document alone; the canonical plan must open its concrete task after reviewed characterization/design evidence.

Current model callbacks remain trusted. The service does not prove safe arbitrary deletion callbacks, replaced IDs, affected-row receipts, reference forgery, filesystem aliases/provenance, adapter atomicity, forensic erasure, caller rollback recovery or unknown commits. A caller may roll back SQL after bytes vanish, and an adapter may act before false/throw. Real schema/owner approval, private-file/DB/key restore, target-host/engine proof, maintenance fencing and deployment remain open. Phase 4 stays IN PROGRESS; the other finite families and all 39 approved UI screens remain unimplemented in their owning phases.
