# Legacy Guest/QR metadata writes

## IMPLEMENTED — P4-040 (2026-11-02)

[LegacyGuestWrites](../app/Services/Tenancy/LegacyGuestWrites.php)::mutateWithQr protects only [AdminController](../app/Http/Controllers/AdminController.php)::activateQr, deactivateQr, revoke and restore. It delegates to the unchanged [P4-039 mutate guard](LEGACY_GUEST_WRITES.md); no retained [GuestAccess](GUEST_ACCESS.md) boolean or cached QR relation supplies authority. Customer event/Guest routes remain closed.

Canonical raw original/current Guest PK/Event and actor identity pairs must agree before guard SQL; the Guest Event must equal the explicit selected Event. Those scalar identities stay fixed across the existing default-connection transaction's three attempts. No outer transaction or later mutable request/session selection is introduced.

## Current guard order

| Read | Required current state |
|---|---|
| Event | Captured selected ID; workspace_id exactly null |
| User | Captured identity; raw role exactly admin; known true activity |
| Event reread | Unchanged OfflineEventLockService::assertEventAdmissionDataEditable(currentEvent,true) |
| Blocking Session check | Same helper's active offline Session query |
| Guest | Captured PK/Event constrained in SQL; canonical raw PK/Event postcheck |
| QR | Primary locked current unique guest_id association; canonical raw QR PK and exact guarded Guest parent |

The named successful guard path has **six primary locking reads**, comprising the existing five plus one QR read. This does not count every entry-validation/model-save/request query. The unique guest_id schema is unchanged. If the association changes before the guarded read, the current associated QR is used; neither the previous QR ID nor the caller's cached relationship is authority. A malformed hydrated QR identity/parent refuses 404 before the trusted write.

Current actor loss returns 403; Event ownership/Guest-parent or raw-context loss returns 404. Ordinary, platform-flagged and workspace-linked administrators retain the same exact active-admin predicates, without bypass or new membership/lifecycle rule. Historical archived/cancelled Event controls remain compatible. A late blocking offline Session keeps the existing browser error redirect or JSON 423 feedback.

## Preserved writes

| Existing action | Existing business payload | Missing QR |
|---|---|---|
| activateQr | QR is_active=true; revoked_at=null | 404 |
| deactivateQr | QR is_active=false; revoked_at unchanged | 404 |
| revoke | Optional QR is_active=false; revoked_at=now() | Success |
| restore | Guest status=active through existing status hooks; optional QR is_active=true, revoked_at=null | Success |

Restoration retains current usage and the existing Guest model normalizes the result to active, partially_used or fully_used. Existing preflight checks, redirects and success messages remain. QR token/image path, manual code, pass capacity, counters, admission/check-in history, neighboring rows and file bytes are preserved. No QR generation or file operation occurs. P4-039 update/cancel behavior remains unchanged.

## Verification

| Check | Actual result |
|---|---|
| Original-source HTTP reproduction | 31 cases / 240 assertions / 18 failures / zero errors |
| Independent LegacyGuestQrWritesTest | 36 cases / 424 assertions |
| Existing QR/Guest/scanner/offline compatibility | 75/672 |
| Focused isolated suite | 1,069/21,819 |
| Full isolated backend suite | 2,102 tests / 42,558 assertions |
| Prior classes | All 89 retain exact case/assertion counts |
| Routes / inventory | Unchanged 100 method/path pairs / 32 tables |

Passing suites have zero errors/failures/skips. The original-source HTTP run excludes five direct future-service cases; its 18 failing regressions include order/context observations and are not 18 distinct vulnerability claims. The independent class requires actual SQLite :memory:, SQLite PDO and actual autocommit0, unique TEMP fake public storage and whole-table/file snapshots.

The matrix covers all four actions' late actor role/activity, Event ownership and Guest-parent loss; six primary locking reads/scoped Guest and QR bindings; ordinary/platform/member/nested and historical-Event controls; all missing-QR outcomes; unused/partial/full restoration status; late offline browser/JSON feedback; a real PDO exception after Guest and QR restoration updates; three real SQL retry attempts with frozen identity despite request/session retargeting; current unique association versus a stale cached relation; malformed raw QR identity/parent; and invalid selected context before SQL/transaction/callback.

The SQL rollback case observes both writes before an intentional missing-table SQL failure and verifies exact rollback. Retry cases use a real SQLite trigger/PDO failure with a synthetic database-is-locked message, establishing three attempts and conservation rather than actual contention. Recorded ambient-caller behavior retains ordinary rollback of both writes.

Only the four controller methods change; all 87 other methods retain exact tokens and controller bytes outside those methods are identical. The helper constructor/mutate and bytes outside the added QR import/method are preserved. All 408 unrelated non-document baseline paths and 360 frozen tested-source paths match, including environment/app key, Git index and existing builds. All 39 original PNGs, five protected UI references, 20 previous UX rows, eight reference batches and 39 NOT STARTED screen statuses remain unchanged.

## Scope and limits

Trusted callbacks and update cancellation remain inherited composition; no new persisted-write receipt, after-write authority, caller-rollback or uncertain-commit guarantee is added. SQLite builder/retry observations do not prove actual MySQL lock schedules. Customer event exposure and real-data/restore/ownership/deployment gates remain open. Model/write callbacks remain trusted; no strict update() return check or persisted-write receipt is added. Arbitrary callbacks, after-write authority changes, external writers/connection manipulation and final commit certainty are outside this named pre-write boundary.

No generation, bytes, deletion/import/export/report, audit/notification, schema/route/UI, shared service extraction, customer exposure, configured data/account/ownership/service, dependency/build/environment or deployment change is added. Static public QR assets and remaining legacy resource surfaces still need their recorded boundaries. Invitation/RSVP and all 39 approved UI screens remain planned in their owning phases.

Next: perform a finite remaining-surface/Phase4 exit audit before choosing another source task; do not automatically extend this family into callback or receipt hardening. Phase4 remains IN PROGRESS.

[Architecture](ARCHITECTURE.md) · [API](API_CONTRACT.md) · [Database](DATABASE_CONVENTIONS.md) · [Security](SECURITY_MODEL.md) · [Tests](TEST_STRATEGY.md) · [Plan](PROJECT_PLAN.md) · [Phase4](phases/PHASE_04_AUTHORIZATION.md)
