# Legacy single-Guest writes

P4-040 extends the unchanged mutate guard through mutateWithQr for four existing Guest/QR metadata actions. Its sixth read locks the current unique QR for the guarded Guest, checks raw identity/parent and ignores cached caller relations. The dated P4-039 evidence below remains limited to update/cancel. Latest full gate: **2,102 tests / 42,558 assertions**. [Contract and limits](LEGACY_GUEST_QR_WRITES.md).

## HISTORICAL IMPLEMENTATION — P4-039 update/cancel only (started 2026-11-01; completed 2026-11-02)

[LegacyGuestWrites](../app/Services/Tenancy/LegacyGuestWrites.php) protects only [AdminController::update/cancel](../app/Http/Controllers/AdminController.php), the existing legacy single-Guest metadata/cancellation transactions. It does not open customer Guest/event routes or extract a general GuestService/AdmissionService. Dormant [GuestAccess](GUEST_ACCESS.md) remains separate and supplies no retained write permission.

Before guard SQL, mutate(Guest,User,explicit selected Event,Closure):void parses canonical positive PHP-range IDs from the Guest's raw original/current PK and event_id and actor's raw original/current identity. Those pairs must agree and Guest Event must equal explicit selected Event. Invalid, retargeted, padded, floating or overflowing caller context refuses before SQL/transaction entry. Canonical integer/string representations remain valid, including PHP_INT_MAX. No permissive coercion, later selected-session lookup or default Event fallback supplies authority.

Scalar identities stay fixed through existing default-connection transaction's exactly three attempts. The controller freezes already validated payload by value before entering the service; retries do not reread mutable request fields.

## Current guard order

Each attempt runs these primary locking reads before its trusted callback:

| Read | Required current state |
|---|---|
| Event | Captured selected ID; workspace_id exactly null |
| User | Captured identity; raw role exactly admin; known true activity |
| Event reread | Existing OfflineEventLockService::assertEventAdmissionDataEditable(currentEvent,true) |
| Blocking Session check | Same unchanged helper's active offline Session query |
| Guest | Captured PK/Event in SQL; raw PK/Event postchecked after hydration |

The successful guard has **five reads**, not four; this does not count all entry-validation/model-save/request queries. OfflineEventLockService remains unchanged. Current ownership/Guest-parent loss returns404 and current actor loss403. A late blocking offline Session retains existing OfflineEventLockedException browser error redirect or JSON423 message.

Ordinary, platform-flagged and workspace-linked administrators remain eligible only when current raw role is exactly admin and activity is known true. Platform flags/memberships add neither bypass nor new eligibility requirement. Historical archived/cancelled Event controls remain compatible; no lifecycle/retention rule is added.

## Existing writes and admission conservation

Initial entry checks remain. Update retains request validation, phone normalization, Single/Double/Special mapping, four business fields (name,phone_number,pass_type,allowed_entries), redirect and success message. Its callback repeats normalized allowance >= current locked used_entries using same allowed_entries feedback:

> Allowed entries cannot be less than the N entries already used.

When admissions rise after initial validation, a lower edit fails before update; legitimate usage and new history survive instead of being reduced by model's existing clamp. Cancellation retains only status=cancelled as business payload and existing redirect/message. Model pass/status rules remain unchanged; no counter/history, manual-code, QR association/token/image or admission-algorithm rewrite is introduced.

The service adds no outer/nested transaction. Recorded ambient-caller control enters one level below caller and retains ordinary rollback behavior. A trusted callback runs once in each guarded attempt; database retry can re-enter it up to unchanged three-attempt limit.

## Verification

| Check | Final result |
|---|---|
| Original-source HTTP reproduction | 30 cases / 241 assertions / 22 failures / zero errors |
| Independent LegacyGuestWritesTest | 38 cases / 727 assertions |
| Existing Guest/offline compatibility | 16 tests / 132 assertions |
| Focused isolated suite | 974 tests / 20,855 assertions |
| Full isolated backend suite | 2,066 tests / 42,134 assertions |
| Prior classes | All 88 retain exact case/assertion counts |
| Routes / inventory | Unchanged 100 method/path pairs / 32 tables |

Passing suites have zero errors/failures/skips. The class requires actual SQLite :memory:, actual SQLite PDO and autocommit0, unique TEMP storage, fake public files and whole-table/file snapshots. Its 30 HTTP cases cover late actor role/activity, Event ownership, Guest reparenting/coherent selected retargeting; frozen selection/request payload; increased current admissions; five primary locking reads/scoped Guest SQL; ordinary/platform/member/nested and historical-Event controls; late offline browser/JSON feedback; PHP_INT_MAX; real partial-write rollback; and exactly three failing SQL attempts with frozen IDs/payload. Eight direct cases reject malformed/mismatched original/current/selected IDs before every SQL/transaction/callback.

Original-source reproduction excludes eight direct future-service cases. Retained initial-red-38draft HTTP had30/239,20 failures and two ModelNotFoundException errors. Final retry assertion checks actual QueryException type, so old retargeted lookup's wrong exception becomes an assertion failure; named scope is unchanged. The22 final failing regressions include required order/context observations and are not22 distinct vulnerability claims.

Partial-write cases observe a real Guest UPDATE before a real missing-table PDO failure, then verify exact rollback. Retry cases use an actual SQLite trigger raising a synthetic database-is-locked message; they verify three real failing UPDATE attempts and final conservation, not actual contention.

Syntax/read-only scoped Pint pass for new service/test and changed methods. Full-controller Pint retains same seven pre-existing fixer categories observed on preserved TEMP baseline; unrelated formatting stays intact. Only two controller methods and one import change; other89 methods retain exact tokens and remaining controller bytes are identical. All405 unrelated non-document paths and359 frozen source paths match, including environment/app key, Git index and builds. All39 original PNGs, five reference UI documents,20 prior UX rows, eight reference batches and39 NOT STARTED statuses remain unchanged. Documentation has92 Markdown references after this addition.

## Scope and limits

This establishes named pre-write authority and allowance conservation. Trusted callbacks/model callbacks remain trusted. No strict update() return check or persisted-write receipt is added; inherited callback cancellations are not newly certified as successful persistence. After-write authority changes, arbitrary malicious callbacks, external writers/connection manipulation, caller rollback and unknown commit outcomes remain outside this guarantee. No file/audit/notification/provider effect or generic error-handling redesign is added.

SQLite verifies named transaction/builder intent and fixture conservation, not actual MySQL locking, target-host isolation or durable commits. Entry/read-only/bulk/create/delete/import/export/report/QR/file actions remain separate. Static public QR assets, general Guest/admission extraction, complete isolation and customer event exposure remain open. Real database/restore/ownership/fencing/deployment, public signup and all approved-reference UI implementation stay gated/planned. Phase4 remains IN PROGRESS.

[Architecture](ARCHITECTURE.md) · [API](API_CONTRACT.md) · [Database](DATABASE_CONVENTIONS.md) · [Security](SECURITY_MODEL.md) · [Tests](TEST_STRATEGY.md) · [Plan](PROJECT_PLAN.md) · [Phase4](phases/PHASE_04_AUTHORIZATION.md)
