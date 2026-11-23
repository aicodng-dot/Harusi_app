# Preview retry artifact recovery

## CURRENT — implemented bounded repair (P4-034, 2026-10-29)

P4-034 implements cleanup only for this invocation's known failed root callback artifacts after actual framework retry entry. The latest complete isolated source gate is **1,901 tests / 36,878 assertions**, with100 unchanged routes and32 inventory tables. R32 is partly mitigated for these named outcomes; this is not general file, commit or crash recovery. [Outcome audit](OFFLINE_UPLOAD_FILE_EFFECTS.md).

## IMPLEMENTED — root callback retry protocol

1. Eligibility starts at default-connection framework level0 and actual PDO autocommit. Every callback resets its current-attempt path/disk before any failing guard. Attempt identity, generated path, actual private disk used for put and actual PDO are captured; Import/audit metadata use that disk.
2. Only the callback's exception path may register a rollback marker, after a path is assigned and while root eligibility, level1, the same PDO and PDO::inTransaction() hold. Failed observation/registration preserves the original exception and grants no marker. A callback that returns successfully is never marked merely because later commit handling throws.
3. The afterRollBack callback only marks its immutable operation-local attempt/path/disk/PDO. It performs no query, file or logging effect. A notification alone is not deletion authority.
4. A marked prior artifact is considered only if this invocation actually enters a later callback. The preserved Event → current active administrator → Session prefix must pass again. Current root level/open-PDO invariants and identity with the prior artifact's captured PDO are required before cleanup.
5. A current primary locking ID-only Import lookup checks ANY exact encrypted_file_path match, without Event or disk prefilters and without hydrating a foreign private Import. Any reference vetoes cleanup before file existence/deletion or another put, including a foreign Event claim and NULL/different disk metadata. Absence alone never grants authority.
6. The private cleanupRolledBackPreviewArtifact helper accesses only the immutable captured disk/path. Missing files are treated as cleaned. False or throwing deletion stops before another put and leaves its cleanup outcome unresolved. Storage exceptions are wrapped in a fixed-message RuntimeException so even a concurrency-shaped storage message cannot enter Laravel's database retry path. A marker is removed from local pending state only after cleanup succeeds or absence is established.
7. The existing three-attempt transaction is retained, with no outer transaction, global listener, artifact scan or retention/quarantine policy. The inherited final catch is limited to the current attempt and captured disk; resetting that pointer prevents a later refused callback from deleting an earlier possibly committed file. This does not certify that catch for same-attempt unknown/final-commit outcomes, enclosing caller rollback or durable reconciliation.

| Observation | Current bounded behavior |
|---|---|
| Failed root callback while its same PDO transaction is open; marker runs; later authorized callback enters | Remove only that known prior artifact, after the reference veto and before the next put |
| Marker notification but no later callback entry | No deletion by the new retry protocol; the separately limited final catch retains its current-attempt behavior |
| Retry2 succeeds after one SQL abort | Earlier marked file is removed; second committed file and all neighboring rows/files remain |
| Three callback SQL aborts | Earlier files are removed before later puts; the existing final catch removes the final failed attempt file |
| Ordinary nonconcurrency refusal | Existing single-attempt cleanup remains |
| Missing prior file | Treat as cleaned and continue without delete |
| False/throwing deletion, including transient concurrency-shaped storage exception | Stop generically before another put; leave the artifact outcome unresolved for separate reconciliation |
| Event becomes owned or administrator becomes inactive before retry | Refuse404/403 before further protected file access; retain the earlier file |
| Disk configuration changes between callbacks | Clean the old marked artifact on its captured disk; protect a same-path neighbor on the new disk |
| Any Import references the marked path | Veto before file access/new put, regardless of Event or disk metadata |
| Controlled raw PDO commit before callback throw, with/without raw begin afterward | Preserve the committed Import/file; commit-only fails marker eligibility, while commit-then-begin requires the positive-reference veto |
| Successful callback followed by commit-class error | Earlier file remains unmarked across a later callback, even when no committed reference exists |
| PDO closed/replaced or state cannot be established | No new cleanup grant; immutable PDO identity is rechecked before consuming a prior marker |
| Nested caller or later enclosing rollback | No new root marker path or caller-owned rollback reconciliation |

## VERIFIED — P4-034 source and regression gate

The independent OfflinePreviewRetryArtifactCleanupTest passes **17 cases / 783 assertions** at actual SQLite :memory: autocommit0 with fake private files. Existing workflow **11/189**, focused **800/15,534** and full **1,901 tests / 36,878 assertions** pass with zero errors, failures or skips. All85 prior classes preserve exact case/assertion counts. The original-source16-case run reproduced14 failures /324 assertions /zero errors, with two compatibility controls passing. These are regression outcomes, not14 distinct vulnerability claims.

The initial16-case candidate passed16/749. A subsequent transient storage deletion exception containing a concurrency-shaped message reproduced one failure /3 assertions /zero errors; the fixed-message storage wrapper then made the final17-case matrix pass. The storage error now stops before another put even when its original message resembles a database retry. These controlled exceptions and explicit PDO commits are not real contention or lost-acknowledgment simulations.

Scoped PHP syntax/read-only Pint and unchanged100-route/32-table safe CLI pass. Token-normalized comparison preserves19 existing methods and permits only storePreview plus one private cleanupRolledBackPreviewArtifact helper; the current Event/active administrator/Session prefix and Guest/child/status processing remain intact. All362 unrelated non-document baseline paths and354 frozen tested-source paths match, including environment/app key, Git index and existing builds. No migration, route, grant, admission algorithm, configured data/account/ownership/service, dependency/build or deployment change.

The17 independent cases assert actual SQLite PDO/configured :memory:/empty main filename and level0/PDO autocommit before migration, use copied private fixtures and fake private disks, and do not inherit RefreshDatabase or another test class. Exact neighboring row/file and authorized preliminary-audit preservation accompany target effects. Source checks establish the additional physical/PDO eligibility conditions; the named runtime cases do not exhaust lost-connection, nested-manager or PDO-replacement schedules.

The NULL-disk reference shape is a synthetic malformed/legacy compatibility fixture: only that guarded in-memory SQLite test loosens the installed NOT NULL column. No application migration changes. Query grammar instrumentation observes primary/locking/ID-only builder intent, not MySQL row locks. Explicit raw PDO commit/rebegin controls demonstrate observable commits on the same connection; they are not lost-acknowledgment simulations. The path column has no supporting lookup index in this source, and no index is added; engine lock/scan breadth and contention need separate performance/rollout review.

## REQUIRES VERIFICATION — remaining recovery boundaries

This repair partly mitigates R32 for known root callback rollback/retry only. Current authority loss leaves earlier files untouched; unsuccessful cleanup leaves the artifact outcome unresolved for separate reconciliation. The injected false/throwing deletion fixtures fail before unlink and retain bytes by construction. An adapter may act before returning false or throwing; no general byte-retention or undo guarantee follows. A successful callback followed by a commit-class error leaves its earlier artifact unmarked across later callback entry, even when that artifact has no database reference. The inherited final catch still acts on its current attempt and captured disk; same-attempt final/uncertain commit outcomes are not certified safe. Process crashes, lost acknowledgment, durable cleanup-failure reconciliation, enclosing caller rollback, arbitrary external transaction/file/reference manipulation and secure-delete recovery remain open. R33, child recertification and historical/internal return graphs remain separate. SQLite query instrumentation establishes primary/locking/ID-only builder intent, not actual MySQL row-lock behavior; lookup breadth/indexing and engine schedules remain rollout/performance review work. The NULL-disk reference is a synthetic SQLite-only malformed/legacy-schema fixture, not the installed schema. Invitation/RSVP and the common simple UI remain planned; Phase4 remains IN PROGRESS.

The protocol assumes the inspected closed callback performs DML on the single default connection, with no arbitrary external transaction-owner manipulation or committed-reference hard-delete/rebinding. PDO identity/open-state checks cannot identify every commit-then-begin sequence. A positive reference is a defensive veto, not a fence against arbitrary external writers or filesystem aliases. No real private file, account, ownership, service or deployment was changed by these tests. [Risks](RISK_REGISTER.md), [execution](PROJECT_PLAN.md), [test strategy](TEST_STRATEGY.md).

## HISTORICAL — P4-032 observation and P4-033 design (2026-10-28)

P4-032's separate TEMP probe passed3 cases/269 assertions against unchanged P4-031 source at actual SQLite :memory: autocommit0 with fake private files. It reproduced one unreferenced earlier file after retry2 success, two after three failures and none after ordinary single-attempt refusal. The then-current P4-031 regular gate was1,884 tests/36,095 assertions, retained by353 source fingerprints rather than rerun. These are historical pre-repair outcomes, preserved in the [upload audit](OFFLINE_UPLOAD_FILE_EFFECTS.md).

P4-033 completed two independent read-only design reviews without implementing cleanup. They distinguished callback/commit/nested/rollback-manager paths, rejected rollback notifications or missing references as sufficient deletion authority, required immutable same-PDO candidates and the any-path positive-reference veto, and retained unknown commit/crash/caller/cleanup-reconciliation limits. P4-034 implements only the bounded continuation above; neither the design nor this repair resolves all of R32 or R33. [Historical task records](phases/PHASE_04_AUTHORIZATION.md).
