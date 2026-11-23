# Offline upload and file-effect boundaries

P4-038 implements three separate one-attempt secure-delete stages and actual persisted marker/full audit verification after P4-037 design. It preserves named normal SQL-refusal partial effects and explicitly changes protected after-write callback rollback windows; unlink has no SQL undo. Full source gate: **2,028 tests / 41,407 assertions**. [Current protocol](OFFLINE_SECURE_DELETE_BOUNDARIES.md).


Historical P4-037 design checkpoint (2026-10-29): P4-037 is a later-write design audit only. The planned metadata and audit stages independently revalidate current authority and asset/marker context in one attempt each, without another byte operation or an outer transaction. Existing named autocommit refusal outcomes and new callback/commit windows are distinguished explicitly; runtime later-write authority remains open. [Planned contract](OFFLINE_SECURE_DELETE_BOUNDARIES.md).


Historical P4-036 byte-only checkpoint (2026-10-29): P4-036 implements the secure-delete byte-phase fence reviewed in P4-035: one current-authority transaction attempt around the shared byte primitive, with unchanged metadata UPDATE/audit INSERT outside that new scope. Full source gate: **1,937 tests / 37,724 assertions**. R33 is only partly mitigated; unlink still has no SQL undo or general recovery guarantee. [Current contract](OFFLINE_SECURE_DELETE_BOUNDARIES.md).


P4-034 implements the bounded retry protocol reviewed in P4-033 for the known root callback rollback artifacts reproduced in P4-032. Its P4-034 source gate was **1,901 tests / 36,878 assertions**. This is partial R32 mitigation, with final/uncertain commit, crash, cleanup-failure reconciliation and R33 still open. [Implemented protocol and limits](OFFLINE_RETRY_ARTIFACT_RECOVERY.md).


## HISTORICAL IMPLEMENTATION — P4-036 secure-delete byte authority (2026-10-29)

P4-036 routes both existing destroy actions through LegacyOfflineSecureDeletion::delete with explicit selected Event and canonical original/current Artifact, Session, Import Event and actor identities captured before queries. It freezes exact raw path/disk and the resolved fallback disk before the composed-helper hook. Its trusted optional byte-context provider uses exactly one default-connection transaction attempt: primary selected unowned Event → current exact active administrator → captured Session → Artifact. SQL namespace predicates constrain unowned Event, Session Event and Artifact parent/Import Event before full model hydration; raw post-hydration identity/parent/path/disk checks remain. Undeleted state is checked before bytes.

The shared FileStorage deleteEncryptedArtifactBytes primitive preserves private path/disk, missing-file and false/throw behavior and intentionally rechecks the existing global retention enable flag at the byte point. The provider performs the byte phase and returns current resource/Session/actor/path/disk. Provider and direct branches are exclusive: no ordinary parent reload, fallback on provider error, disk recalculation or second unlink follows provider success. Without a provider, trusted direct helpers retain their existing composition and named outcomes, with the added shared byte-point retention recheck. No age, archive or lifecycle prerequisite is introduced.

Only the byte phase is fenced here. The existing metadata UPDATE → audit INSERT → final fresh() tails remain outside the new byte transaction and unchanged. Current authority for those later writes is not implemented by P4-036. Under normal HTTP autocommit, metadata refusal still loses bytes without metadata/audit, and audit refusal still loses bytes while retaining committed metadata. An enclosing caller transaction still changes SQL commit/rollback scope; unlink remains irreversible. Both destroy actions now pass late HttpExceptionInterface403/404 through before their RuntimeException catch; success/validation behavior and generic storage/SQL feedback remain. No retry around unlink is added.

The independent LegacyOfflineSecureDeletionBytesTest passes **36 cases / 846 assertions**. Existing deletion compatibility passes **17/353** with all prior failure-outcome fields and assertions intact; import workflow **11/189**, focused **836/16,380** and full **1,937 tests / 37,724 assertions** pass with zero errors, failures or skips. All86 prior classes retain exact case/assertion counts.

The original-source HTTP-only run reproduced **30 failures / 156 assertions / zero errors in30 cases**. Four direct future-service identity cases and two direct-helper controls were excluded from that original-source filter. Some failures are newly required context/transaction observations, not30 distinct vulnerability claims. The fixed matrix checks both artifacts for late ownership/actor/Session/Artifact/path/disk/deleted-state/retention changes, no byte access on refusal, current provider context, ordinary/platform/member administrators, historical archived/revoked packages and failed results, missing file/path, frozen fallback disk, no double unlink, malformed direct service context and one-attempt concurrency-shaped storage failure.

Five scoped PHP syntax/read-only Pint checks and unchanged100-route/32-table safe CLI pass. Token-normalized comparison preserves28 existing methods, permits four existing method changes and adds the shared deleteEncryptedArtifactBytes primitive; the metadata/audit/fresh tails are identical. One scoped tenancy service and one independent test class are added. All361 unrelated non-document baseline paths and356 frozen tested-source paths match, including environment/app key, Git index and existing builds. No configured retention flag/configuration, migration/schema, route, grant, admission algorithm, actual data/account/ownership/service, dependency/build or deployment was changed.

R33 is partly mitigated only for current authority at the named secure-delete byte phase. Later metadata/audit current authority and the inherited final fresh() graph remain separate. Shared-path ownership/provenance, path aliases, historical encrypted-content attribution, arbitrary external writers, partial adapter effects, byte-phase commit uncertainty, caller-owned transactions, forensic erasure and durable file/SQL recovery remain open. A false/throwing adapter may already have changed bytes; the injected storage faults retain bytes by construction and establish no general undo guarantee. The single attempt prevents framework retry around unlink but cannot restore a file or prove an uncertain commit outcome. SQLite observations establish the named transaction/builder intent and conservation cases, not actual MySQL row-lock waits or durable storage. R32's remaining preview commit/crash/reconciliation limits also stay open. Invitation/RSVP and the common simple UI remain planned; Phase4 and actual rollout gates remain IN PROGRESS.

## IMPLEMENTED — P4-034 known root callback retry cleanup (2026-10-29)

P4-034 implements bounded cleanup for artifacts from a failed root transaction callback only after this invocation actually enters a later retry. Each callback resets its current cleanup path/disk before guards; immutable marked attempts retain their path, disk and actual PDO. Registration occurs only in the callback catch with root entry, level1, same-PDO and an open physical transaction. The rollback callback only marks local state. A later callback rechecks the current Event → active administrator → Session prefix and root/PDO identity before cleanup.

Before touching a prior file, a current primary locking ID-only Import lookup vetoes ANY matching encrypted_file_path, without Event or disk filtering or foreign private Import hydration. The captured disk selects the file. A missing file is tolerated; false/throwing deletion stops before another put. Storage exceptions are wrapped with a fixed nonconcurrency message so Laravel cannot silently retry them as database contention. No outer transaction, global listener, artifact scan or retention/quarantine policy is added.

| Controlled outcome | P4-034 behavior |
|---|---|
| First callback SQL abort, retry2 succeeds | Remove known prior file before the new put; retain the committed second file and exact neighbors |
| Three callback SQL aborts | Remove earlier artifacts before subsequent puts; the existing current-attempt catch removes the final failed artifact |
| Ordinary nonconcurrency refusal | Keep the established single-attempt cleanup |
| Missing prior file | Continue without a deletion |
| False/throwing cleanup, including transient concurrency-shaped storage throw | Stop before another put, use generic feedback and leave the prior artifact outcome unresolved |
| Current ownership/actor loss before retry | Return404/403 before further protected file access; retain the prior file |
| Any current Import references the path | Veto before file existence/deletion/new put, including foreign Event and NULL/different disk metadata |
| Successful callback followed by a commit-class error | Earlier artifact stays unmarked across retry; it is outside this repair even if unreferenced |

The independent OfflinePreviewRetryArtifactCleanupTest passes **17 cases / 783 assertions** at actual SQLite :memory: autocommit0 with fake private files. Existing workflow **11/189**, focused **800/15,534** and full **1,901 tests / 36,878 assertions** pass with zero errors, failures or skips. All85 prior classes preserve exact case/assertion counts. The original-source16-case run reproduced14 failures /324 assertions /zero errors, with two compatibility controls passing. These are regression outcomes, not14 distinct vulnerability claims.

The initial16-case candidate passed16/749. A subsequent transient storage deletion exception containing a concurrency-shaped message reproduced one failure /3 assertions /zero errors; the fixed-message storage wrapper then made the final17-case matrix pass. The storage error now stops before another put even when its original message resembles a database retry. These controlled exceptions and explicit PDO commits are not real contention or lost-acknowledgment simulations.

Scoped PHP syntax/read-only Pint and unchanged100-route/32-table safe CLI pass. Token-normalized comparison preserves19 existing methods and permits only storePreview plus one private cleanupRolledBackPreviewArtifact helper; the current Event/active administrator/Session prefix and Guest/child/status processing remain intact. All362 unrelated non-document baseline paths and354 frozen tested-source paths match, including environment/app key, Git index and existing builds. No migration, route, grant, admission algorithm, configured data/account/ownership/service, dependency/build or deployment change.

This repair partly mitigates R32 for known root callback rollback/retry only. Current authority loss leaves earlier files untouched; unsuccessful cleanup leaves the artifact outcome unresolved for separate reconciliation. The injected false/throwing deletion fixtures fail before unlink and retain bytes by construction. An adapter may act before returning false or throwing; no general byte-retention or undo guarantee follows. A successful callback followed by a commit-class error leaves its earlier artifact unmarked across later callback entry, even when that artifact has no database reference. The inherited final catch still acts on its current attempt and captured disk; same-attempt final/uncertain commit outcomes are not certified safe. Process crashes, lost acknowledgment, durable cleanup-failure reconciliation, enclosing caller rollback, arbitrary external transaction/file/reference manipulation and secure-delete recovery remain open. R33, child recertification and historical/internal return graphs remain separate. SQLite query instrumentation establishes primary/locking/ID-only builder intent, not actual MySQL row-lock behavior; lookup breadth/indexing and engine schedules remain rollout/performance review work. The NULL-disk reference is a synthetic SQLite-only malformed/legacy-schema fixture, not the installed schema. Invitation/RSVP and the common simple UI remain planned; Phase4 remains IN PROGRESS.

## HISTORICAL OBSERVATION — P4-032 deterministic retry/file probe (2026-10-28)

P4-032 confirms a deterministic callback-rollback/retry artifact gap in the unchanged preview source. At actual transaction level0 on SQLite :memory: with fake private storage, a child INSERT trigger fails after a successful file put. A synthetic `database is locked` message is recognized by the installed Laravel concurrency detector and retries the callback after SQL rollback. Success on retry2 leaves one earlier unreferenced attempt file beside the committed file; three failed attempts leave two earlier unreferenced files after only the final path is cleaned. An ordinary nonconcurrency refusal takes one attempt and leaves no attempt artifact.

| Injected child INSERT refusal | Attempts / puts | Remaining attempt files | Committed references | Unreferenced attempt files |
|---|---|---|---|---|
| Synthetic concurrency message once, then success | 2 / 2 | 2 | 1 | 1 |
| Synthetic concurrency message on all three attempts | 3 / 3 | 2 | 0 | 2 |
| Ordinary nonconcurrency refusal | 1 / 1 | 0 | 0 | 0 |

Counts cover new attempt files, excluding retained neighboring fixture files. The external TEMP probe passed **3 cases / 269 assertions** (1.128945s), with zero errors/failures/skips. It asserts SQLite :memory: before migrations and actual autocommit0 at upload, observes SQL rollback/retry and unique attempt paths, and preserves the legitimate Uploaded trace and neighboring rows/files. The original source remains unchanged: all353 tested-source fingerprints and363 unrelated non-doc paths match. P4-031 retains **1,884 tests / 36,095 assertions** by exact preservation, not a new regular-suite run.

These are observations of current behavior using invented rows/bytes and message-based fault injection, not a runtime repair, real lock conflict or MySQL schedule. Known SQL rollback/retry is distinct from final-commit failure, lost acknowledgment, ambiguous commit, cleanup failure and crash recovery; those outcomes remain unverified. No cleanup, retention, quarantine or deletion policy changes. Do not infer that possibly committed files may be deleted.

The retry-artifact part of R32 is now reproduced for these deterministic cases. Lost acknowledgment, uncertain/final-commit outcome and cleanup failure remain unverified. R31 named head-write protection below stays implemented; R33 remains open. [Test strategy](TEST_STRATEGY.md).

## IMPLEMENTED — P4-031 preview storage authority (2026-10-28)

storePreview now captures canonical original/current Session PK/Event and actor identities, explicit selected Event and validated envelope Event equality before its transaction. Captured IDs remain fixed across its existing three attempts. Inside that same transaction and cleanup catch, primary locks run Event → current active exact administrator → Session; the Event must remain unowned and the Session must retain the captured Event. Platform and workspace-linked administrators retain existing eligibility. Package/session-user relations load only after these head checks, and all preview references/audits use the current locked actor and Session.

P4-030's external Uploaded/VerificationFailed/Reimport transactions remain separate from preview success under normal HTTP autocommit. P4-031 now encloses preview records/files and the internal duplicate audit with the current head checks inside its existing transaction and cleanup catch. Invalid raw locked Session Event retains its established validation; canonical parent/ownership mismatch is404 and current actor loss403. Existing crypto/payload validation and file/retry/cleanup order are unchanged.

Boundary **13/363** and full **1,884 tests / 36,095 assertions** verify this named head-write scope. Two deterministic caught post-put/child-insert probes preserve the preliminary audit, roll back preview rows and remove invented fake-disk attempt bytes. They establish no crash, uncertain-commit, cleanup-failure or real-storage recovery guarantee. Package/scanner child recertification, returned/internal historical graphs and artifact/deletion recovery remain separate. [Detailed evidence](TEST_STRATEGY.md).

P4-030 and P4-029 checkpoints below describe their historical source, including gaps subsequently addressed by P4-031. R31's named head writes are now guarded; R32–R33 remain open.

## HISTORICAL — P4-030 preliminary audits checkpoint (2026-10-28)

LegacyOfflineUploadAudits validates canonical original/current Session/Event and actor identities plus explicit selected Event before queries. Each external Uploaded, VerificationFailed or Reimport audit uses its own default-connection transaction with primary Event → current active exact administrator → Session locks. The Event must remain unowned and the Session must retain the captured Event. Platform and workspace-linked administrators retain existing eligibility and the generic audit format is unchanged.

verifyUpload now receives selected Event from the existing controller and uses the guarded current Session/actor instead of default-read fresh() substitution. Failed validation and the external duplicate attempt recheck authority before their audit insert. Late ownership/parent mismatch 404 and administrator eligibility 403 pass through the preview controller's broad catch. Authorized invalid files retain normal validation feedback and legitimate audit traces. No outer upload/preview transaction was added: under normal HTTP autocommit, preliminary audits persist independently of preview success. An existing caller transaction may still enclose them; these are not autonomous commits.

Boundary 16/282 and full **1,871 tests / 35,732 assertions** verify this external-audit scope. Preview storage, crypto/file/retry/cleanup ordering and its internal duplicate audit remain unchanged. R31 is partly mitigated; this is not complete upload isolation or file recovery. [Tests](TEST_STRATEGY.md).

## HISTORICAL — P4-029 source audit (2026-10-28)

This audit changes documentation only. The retained P4-028 source gate is **1,855 tests /35,450 assertions**,100 routes and32 inventory tables. It does not implement new authority checks, repair files, inject a new failure or certify deployment. See [plan](PROJECT_PLAN.md), [sync](OFFLINE_SYNC.md) and [test strategy](TEST_STRATEGY.md).

## P4-029 observed source boundaries

| Boundary | Current behavior | Consequence / evidence |
|---|---|---|
| [Preview controller](../app/Http/Controllers/Admin/OfflineScannerResultImportController.php) | Authenticated legacy parent/Gate and request validation precede verifyUpload. Its broad Throwable catch currently handles exceptions after the ValidationException catch. | These are entry observations. New late authorization exceptions need explicit passthrough, as confirmation/resolution already use. |
| [verifyUpload](../app/Services/OfflineScannerResults/OfflineScannerResultsImportService.php) | Validates filename/size, hashes bytes, calls default-read Session::fresh with package/session-user relations, then records RESULTS_UPLOADED before envelope/decryption validation. | A fresh() result is not a current locked namespace. Current Event ownership/actor checks must precede each protected audit write. |
| Failed verification | ValidationException records RESULTS_VERIFICATION_FAILED, then rethrows. Both preliminary audit writes are outside storePreview's transaction. | Authorized failed attempts retain their audit trace under normal HTTP autocommit. One outer transaction around verifyUpload would roll those records back. |
| Duplicate export | assertResultsExportHasNotBeenUploaded can record REIMPORT_ATTEMPTED. It is called during validation and again inside storePreview. | The two call sites have different transaction boundaries; do not add an Event/User lock after the existing Session lock through an indiscriminate helper replacement. |
| Preview storage | storePreview starts its existing three-attempt transaction, locks Session, creates Import, writes encrypted bytes, writes children/status/audit and commits. Its surrounding catch attempts to delete storedPath on failure. | Add current authority inside this existing transaction/cleanup boundary. A new outer commit would happen beyond this catch and could bypass cleanup. |
| Preview retries / ambiguous commit | A single storedPath variable is shared across closure attempts and reassigned after a new Import UUID. The catch deletes that remembered path. | **Source inference requiring reproduction:** earlier attempt paths and uncertain commit outcomes need outcome-aware reconciliation. This audit does not prove an orphan or a particular driver's lost-acknowledgment behavior. Blindly deleting every path after an ambiguous commit could remove committed data. |
| [Secure-delete helpers](../app/Services/OfflineScannerFileStorage.php) | Retention flag, default parent relation read, exists/delete bytes, metadata UPDATE, audit INSERT, final fresh(). There is no enclosing transaction. | Filesystem unlink cannot roll back with SQL. Existing [deletion feedback tests](../tests/Feature/OfflineScannerDeletionFeedbackTest.php) intentionally verify bytes lost on metadata refusal and bytes plus committed metadata on audit refusal. These are isolated fake-disk observations. |

The generic [audit service](../app/Services/OfflineScannerSessionAuditService.php) simply inserts supplied Session/Event/actor references; it is not an authority service. Current [archive](LEGACY_OFFLINE_FILE_ARCHIVES.md) and [delivery](LEGACY_OFFLINE_PACKAGE_DELIVERY.md) wrappers do not automatically protect upload or secure deletion.

## PLANNED — bounded continuation

1. **P4-030 COMPLETE: preliminary upload audit authority.** External audit guards remain implemented independently of preview success; see the current section and historical checkpoint.
2. **P4-031 COMPLETE: preview authority inside storePreview.** Current Event/administrator/captured-Session checks now run before records/files and internal duplicate handling inside the existing transaction/cleanup boundary. The current section records scope and limits; child recertification and return graphs remain separate.
3. **P4-032 observation, P4-033 design and P4-034 bounded repair COMPLETE.** Known prior root callback rollback artifacts are now cleaned only after a later authorized same-PDO callback and any-path reference veto. This does not close the remaining item3 outcomes: same-attempt final commit failure, lost acknowledgment, unknown outcomes, durable cleanup-failure reconciliation, caller rollback and crash behavior require separate contracts. SQLite callback retry is not evidence for those outcomes.
4. **P4-036 byte and P4-038 metadata/audit authority COMPLETE in their named stages; general recovery remains separate.** Hold current namespace through authorized effects, but explicitly specify the new DB/file outcome contract and test both. A transaction cannot make unlink reversible. Preserve retention and generic failure feedback; do not silently introduce quarantine, forensic-erasure guarantees, key destruction or new retention policy.

Items1–2 are complete for the named head writes. P4-034 completes only the known root callback retry part of item3; remaining item3 outcomes and item4 stay open. Package/scanner child recertification and returned/internal graphs remain separate. This document authorizes no real-file operation; actual ownership/restore/hosting/device/release evidence remains relevant.

## Acceptance and limits

- Refuse stale Event/actor/Session context before protected writes or private file access; preserve neighboring rows/files and session context.
- Keep authorized failure/reimport audits with safe metadata and exact current lineage; do not turn earlier successful checks into reusable grants.
- Preserve valid preview, duplicate handling, cryptographic validation, browser/JSON feedback and current scanner/admission behavior.
- Distinguish SQL rollback, filesystem state and unknown commit outcomes. No source-only review establishes recovery, durable delivery or actual MySQL scheduling.
- Preserve all unrelated source/config/index/build files and the existing tests. Any changed failure-outcome expectations must be named, justified and verified, never weakened to hide partial effects.

R31 named head-write guards remain implemented. R32 is partly mitigated by P4-034 for known root callback retries; unknown commit/crash/caller and durable cleanup-failure reconciliation plus R33 in the [register](RISK_REGISTER.md) remain open. Invitation/RSVP and all-interface simplicity stay in their existing phases.
