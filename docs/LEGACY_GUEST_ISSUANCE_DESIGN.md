# Legacy Guest creation, import and QR issuance design

## Historical P4-044 status and scope

P4-044 completed this source-inspection/design contract on 2026-11-03. At that checkpoint it was a proposed implementation, with no installed guard or executed characterization; P4-043 2,166/43,324 was retained by unchanged source. The clauses below preserve that reviewed scope and finite acceptance matrix as historical dependency evidence.

P4-045 subsequently implemented the [whole-family source contract](LEGACY_GUEST_ISSUANCE.md) on 2026-11-04, verified by **2,288 tests / 44,727 assertions**. The source acceptance is separate from this design-only record. Existing authoritative Guest/QR/manual/pass/admission data, customer/UI/Phase5/real-data prerequisites and family 3 media-serving ownership remain as specified; no second system or private-media migration follows.

## Historical source observations, not executed characterizations

Source anchors below refer to the P4-043 frozen source. TEMP source audits record the detailed call graph and hashes; existing tests were read, not rerun for this documentation task.

| Surface | Current source behavior |
|---|---|
| [AdminController::store](../app/Http/Controllers/AdminController.php) (611) | Validates four inputs, creates a Guest inside a three-attempt transaction and calls the separate three-attempt QR helper. It rereads session-selected Event context inside the operation. No current administrator row guard is composed. |
| Import preview/confirmation (362, 1183) | XLSX preview stores rows and a UUID token in the session. Confirmation captures an Event, processes rows separately and clears the token after the loop. The stored preview has no explicit Event/actor binding. Normal selection clears it; the UUID is not a durable concurrent claim. |
| Import row helpers (1305, 1450) | Duplicate lookup precedes the per-row three-attempt transaction. A broad Throwable catch turns creation failures into row failures and continues. Earlier successful rows remain under ordinary HTTP autocommit; no whole-import transaction exists. |
| Explicit/missing generation (722, 124, 1837) | Generates a new random token, creates/updates and reactivates the QR, puts a PNG and then deletes a different old path. The old-delete boolean is ignored. A stale missing-only candidate can reach the rotating helper after another QR appears. |
| Image repair (1875) | Existing valid images return early. Repair can overwrite a deterministic path, then update QR metadata, without its own transaction. A missing QR delegates to generation. Existing-token repair preserves token/activity/revocation. |
| Repair callers (142, 164, 733, 745) | Both ZIP handlers, image preview and image download call ensureQrImage. These GET/download paths can therefore write. Their eventual response/static-media boundary belongs to family 3. |
| [GuestQrImageService](../app/Services/GuestQrImageService.php) (26, 47) | png calls manualCodeFor, which can save a missing manual code. Rendering is not currently write-free. [QrCodeService](../app/Services/QrCodeService.php) only renders supplied content and is a different helper. |

Existing tests include 30 import cases and 15 QR-generation cases. The import renderer-failure control requires failed-row SQL rollback followed by a successful later row and the existing generic reason. It throws before put; it proves no post-put cleanup or commit recovery. RefreshDatabase suites also supply caller transactions, so a new root-only restriction would break supported composition and is not proposed here.

[Guest saving hooks](../app/Models/Guest.php) generate a missing four-digit manual code, enforce pass rules and normalize usage status. The [manual-code service](../app/Services/ManualCodeService.php) preserves leading zeroes and a 10,000-code Event namespace. Existing unique constraints on Event/manual code, QR token and QR/Guest association remain. Contact duplicates are intentionally permitted after add-anyway; add no name/phone uniqueness rule.

The form normalizes Single/Double allowance to 1/2; import validation requires those exact values. Preserve that distinction. Preserve the current duplicate comparison: incoming names collapse whitespace/lowercase, while SQL uses LOWER(TRIM(name)) on stored names; do not silently introduce a different matching algorithm.

Legacy [Event creation](../app/Http/Controllers/AdminEventController.php) retains its authenticated-entry/validated-allowlist scope and creates an unowned Event. This family does not automatically reopen completed Event/staff boundaries for universal write-time actor recertification.

## Proposed service and ownership

Use one dedicated LegacyGuestIssuance service, with private shared guard/render/write helpers rather than new nested transaction-owning helpers. Concrete public contracts:

| Method | Result and purpose |
|---|---|
| create(validated, actor, selectedEventId, generateQr=true) | Guest with its current optional QR; allowlisted normalized creation fields only. |
| importRow(validated, duplicatePolicy, actor, selectedEventId, generateQr) | outcome created/skipped/conflict, optional Guest and generated_qr flag. Policies are exactly skip, reject_if_match and add_anyway. Controller retains existing summary categories/messages. |
| generate(Guest, actor, selectedEventId, missingOnly=false) | Current QR plus generated/replaced flags derived from the guarded state. missingOnly skips a QR that now exists; explicit generation retains intentional rotation. |
| ensureImage(Guest, actor, selectedEventId) | Current QR after a guarded fast-path check or separately authorized repair. Existing QR credentials/status are preserved during repair. |

These names are an implementation target, not an installed API. Event/Guest/QR shared extraction stays in Phase5. Keep workbook parsing/HTTP validation and result presentation outside the service. Move only the authorized duplicate decision into its current row transaction. Keep existing renderer injection and png call compatibility, including the renderer-failure control.

Capture canonical original/current actor identity, selected Event and any supplied Guest PK/Event before SQL, following PositiveIntegerId and existing safe-identity conventions. Invalid actor refuses 403; invalid selected/Guest lineage refuses 404. Copy only validated name, normalized phone, pass type and allowance scalars; construct explicit Event/zero-usage/active fields internally. Never accept caller ownership, manual code, token, path, counters or cached parent/QR relations as authority. Do not reread mutable session/request context to retarget a later row/effect.

## Current authority and one local attempt

Every mutation owns exactly one default-connection transaction attempt. Remove the current controller outer transaction plus nested three-attempt generation composition. create/import calls the same private issuance body within its one attempt, not another public method that starts a transaction. No three-attempt metadata wrapper surrounds bytes, and no put/unlink retry or fallback is added.

Before Guest/QR mutation or write-adapter access, use primary locking reads: captured unowned Event with raw PK/null ownership check; captured exact active admin with raw identity/role/activity check; unchanged OfflineEventLockService Event reread/blocking-Session check; then any existing Event-scoped Guest and its current unique QR with raw canonical association checks. Platform/workspace-linked administrators satisfy the same legacy predicates without a bypass or a new membership grant. Preserve historical Event eligibility rather than inventing an active-only rule.

For creation, insert only after the prefix, require an actually existing canonical new Guest in that Event, and use ordinary hooks. A cancelled save or unsaved create cannot reach PNG/success. For existing Guests, keep current allowance, used entries, status and valid manual code. Strictly require reported true for explicit Guest/QR saves and storage effects; model true is not an affected-row receipt. Callbacks/triggers remain trusted composition, not an automatically expanding post-write certification task.

Caller transactions remain supported: the one local attempt may use a savepoint; SQL/bytes are not one atomic durable resource. Sanitize renderer/storage and ordinary non-SQL callback failures inside the callback, before Laravel can treat a concurrency-shaped message as a nested SQL deadlock. Actual PDO/QueryException retains framework handling before outward sanitation. Real nested deadlocks, caller rollback/retry and uncertain commits remain explicit limits. Do not claim a local successful return is an outer commit.

## Three generation modes and pure rendering

1. Explicit generation rotates to the existing secure random 64-character token source and reactivates/clears revocation exactly as today. It does not reset Guest usage/capacity/cancellation or surviving manual codes. Normal generated/regenerated messages use the guarded result.
2. Missing-only generation rereads current QR under the guard. If one appeared, return generated=false without rotation, reactivation, timestamp or bytes. Process candidates in deterministic Guest-PK order; retain per-Guest completion, not whole-batch file atomicity.
3. Existing-QR image repair preserves exact token, active/revoked state and a nonnull generated_at. It may set a missing generated_at and update its path. Missing QR repair is initial issuance, not replacement of another Guest's QR. For an existing token, require raw string data accepted by the installed ScannerController::looksLikeQrToken matcher (`/^[A-Za-z0-9]{40,128}$/`); preserve that compatibility range rather than impose exactly64 or normalize the stored token. Other token data refuses repair and remains unchanged. Explicit requested rotation may replace an unusable old token after the same current lineage/path guards; a read repair never silently does so. Scanner matching/admission is unchanged.

Make GuestQrImageService::png/manualCodeFor write-free. A missing manual code is allocated/saved only in the authorized issuance body before rendering; valid four-digit strings, including 0000, remain exact. Invalid nonempty codes refuse. png retains the secure-token URL/manual-text/PNG metadata format; no invitation-ticket renderer or second QR is introduced.

Preserve read-only compatible-image access during offline operation. ensureImage first makes a current primary Event/admin/Guest/QR read observation and probes the current image under a one-attempt read composition without the offline *write* veto. Retain the existing existence plus exact manual-code metadata-substring check; this does not decode the QR or prove full PNG/token integrity, and adds no decoder dependency. A compatible image returns without SQL mutation. If repair is needed, end that read composition and enter one fresh mutation attempt with the full Event/admin/offline/Guest/QR prefix; discard all preflight authority/path assumptions and reevaluate current state. This can be two compositions, never two mutation attempts. Do not hold a read transaction around a public mutation method or rotate a QR that appeared between them.

Image probing and all write-capable GET/ZIP repair callers must use this service. The read observation is not certification of the later response body or copied static URLs; family 3 still owns ordinary-media serving. Future guest/public ticket GETs require write-free rendering/refusal and must not inherit legacy administrator repair authority.

## Frozen paths, references and byte outcomes

Freeze the literal public disk and use the current guarded Event/Guest and scalar token to compute the existing path format. Do not load a different Event from a cached relation. Raw current paths are nullable strings; retain null/empty/'0' no-old-byte behavior and refuse unsupported types.

In the mutation composition, before its first adapter resolution/byte effect, check each truthy current raw path and the computed destination for any other QR reference using primary locking ID-only queries, excluding only the current QR PK where one exists. Skip null/empty/'0' old paths rather than querying shared absent values. No Event/Guest/disk/deletion-set filter may hide a reference. Creation needs its assigned Guest PK before computing the path; its earlier guarded INSERT may roll back on refusal. Never claim all path checks precede that required insertion. Bound queries per operation to distinct relevant paths, not a scan of the filesystem. No path index is added; database scan/lock breadth remains an engine question. The separate guarded read composition may already have resolved/probed an image; these mutation vetoes do not certify those read bytes or move family 3's serving policy into this task.

Only after those checks resolve one public adapter. A new destination that already exists must refuse before put, even if no QR references it. Repair may overwrite only its own exact current raw path after the reference veto; a different computed destination must be absent. Existing-file checks are observations, not an atomic create-if-absent guarantee against external writers/aliases. No new token is chosen merely to retry a refused byte destination.

Preserve explicit generation's metadata -> render/put -> different-old-path delete order, with strict true required for put and any old delete. Retire only the frozen old path once; no existence/read/delete loop. Repair retains its write-before-path-metadata order and does not begin deleting old repair paths. No QR/no old path skips retirement. Freeze paths independently of deletion callbacks/renderer-mutated models.

| Outcome | Required result |
|---|---|
| Authority, association, shared reference or destination refusal | No byte write/delete. Known preceding creation SQL rolls back; no success. |
| Renderer/save cancellation/put false or throw | Stop that operation, no repeated byte attempt; known SQL rollback does not undo partial/new/overwritten bytes. |
| New put succeeds, old delete fails or later SQL/commit fails | Stop; new bytes may remain and old bytes may already be missing. Do not certify no effect or automatically unlink a possibly committed file. |
| Successful local return | Count only that completed operation; caller/commit limits remain. |

No automatic orphan cleanup, afterCommit unlink, global callback, recovery ledger, audit schema or file-provenance claim is added. Absence of another QR reference is a positive-reference veto only. R18 and existing file/recovery risks remain open for aliases, unmodelled references, external writers, partial adapters and DB/file/key restoration.

## Import context, duplicates and partial completion

At preview, freeze the selected canonical Event and actor; store their scalar IDs beside rows/token. At confirm, require current captured identities to match that binding and copy row/action data before dispatch. A missing/malformed/mismatched binding clears the pending preview and requires a new upload; older unbound ephemeral previews expire safely. This changes no saved Guest/pass. Preserve XLSX/2MB/5000-row/header/phone/pass validation.

Consume pending rows/token/context locally before starting row dispatch, retaining a private in-memory snapshot. This prevents ordinary sequential reuse after partial interruption when session persistence succeeds. It is not a durable/concurrent claim or exactly-once import guarantee. Do not add a schema, route, session-blocking dependency or background worker to claim otherwise.

Each actionable row has a fresh captured-Event authority prefix, including a duplicate skip. For reject_if_match, perform the existing Event-scoped duplicate lookup on primary inside that row transaction before insertion. An authorized skip makes no creation; add_anyway retains a separate Guest/manual/QR and does not overwrite the matched Guest. A preview-new row that now matches reports the existing conflict. Event-locked cooperating writers serialize their decisions; no uniqueness against arbitrary writers is claimed.

Preserve invalid/skipped/added/new/conflict result shapes and the ordinary renderer-failure continuation control. Capture the actual connection object, PDO, transaction level and PDO-open state before a row. Only a non-SQL renderer/storage/model callback failure creates a new fixed row-failure exception inside that callback; retain that exact object's identity in operation-local state. Let DB::transaction perform rollback. Continue only when its outer catch receives that same exception object and the same captured connection/PDO/transaction context is restored. A replacement rollback exception, actual PDO/QueryException, callback-success/commit failure or changed context is an interruption, sanitized separately. Broad outer-catch sanitation must never manufacture a continuable row failure. This local control-flow correlation assumes trusted callbacks; it is not a durable receipt or proof against connection manipulation.

The fixed exceptions retain no raw previous cause. Do not translate an unverified rollback or commit error into 'not imported'. Authority 403/404 and offline exceptions stop/propagate; database/rollback/commit or unknown outcome stops with fixed interruption feedback rather than continuing every row. A fresh later invocation is not automatically retried.

Earlier completed rows/QRs remain in normal HTTP autocommit; an enclosing caller can still roll back their SQL. On interruption, do not emit the normal Import completed success or claim the whole file was undone. Retain already-observed result rows locally as partial evidence, flag interruption without inventing a final success/failed total, and require a fresh preview before another attempt. Do not flash/render Guest IDs, manual codes or detailed partial results after actor/Event authorization loss: the generic403/404 response remains private. Partial result presentation is allowed only after a fresh current Event/actor access check; otherwise retain generic feedback without private rows. Supported account language/fallback SW applies to narrow new failure/interruption labels through Laravel catalogs. Keep ordinary legacy messages/result keys; add no diagnostic paths, SQL, credentials or raw cause logging. Existing renderer-before-put failure retains its tested generic row reason because rollback is known in that case.

## Finite implementation acceptance

P4-044 required the later bounded implementation to cover this family together and preserve all 91 then-existing test classes (including P4-043) and every case/assertion count. Use guarded actual SQLite :memory:/PDO/autocommit0 fixtures and unique TEMP fake files; establish old-source HTTP regression observations before installing candidates. No passing result for this proposed matrix is claimed here.

| Group | Required evidence |
|---|---|
| Context | Raw/canonical actor/selected/Guest/preview IDs, frozen row/payload/session retargeting, PHP-range IDs and no default Event fallback. |
| Current authority | Late role/activity/ownership/Guest/QR changes; primary scoped locked prefix; ordinary/platform/member and historical-Event positives. |
| Creation | Ordinary Single/Double/Special/phone/manual-code rules; cancelled create/save/QR save; initial issuance exactly once; no-QR import. |
| Import | New/skip/add-anyway/new-to-conflict, same-file/cross-Event semantics, binding mismatch, sequential replay limits, known renderer rollback/continuation versus authority/offline/DB interruption and honest prior-row partial results. |
| Modes | Explicit intentional rotation, missing-only appeared-QR no-op, existing repair preserving token/status/timestamp/usage, and missing QR initial issuance. |
| Repair call graph | Preview/download/both ZIP routes; no-write valid-image access during offline lock; needed repair refuses offline; fresh second composition and pure png/manualCodeFor with no hidden saves. |
| Paths/bytes | Current/raw/destination paths, stale cached parent and mutable renderer traps; same/foreign shared references, existing unreferenced destination, allowed own-path overwrite, strict false/partial throw and exact old/new byte outcomes. |
| Transaction/feedback | One local attempt including outer creation/import; concurrency-shaped renderer/storage/model and actual SQL failure; caller/savepoint control, unknown-commit refusal/limits, fixed SW/EN/fallback feedback and no private diagnostics. |
| Conservation | Existing import/QR/manual/scanner/offline/full suite, all prior class/case counts, syntax/scoped style, unchanged routes/inventory, source/environment/index/build and 39 PNG/five UI/status/document preservation. |

Independent review must approve the concrete implementation/test scope before source installation. Add selected synthetic engine/storage observations only where the changed composition requires them, with explicit synthetic prerequisites; SQLite is not MySQL locking or deployed storage proof. Actual constraints/ownership, restore, maintenance fencing, host/device and deployment remain separate gates. At the P4-044 checkpoint family 1 remained OPEN pending implementation. P4-045 later accepted that source contract; families 3/4, Phase5 extraction and all 39 original-reference/simple-UI tasks retain their owners.
