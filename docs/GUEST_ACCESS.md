# Guest and QR access

## IMPLEMENTED — P4-040 legacy Guest/QR metadata (2026-11-02)

P4-040 (completed 2026-11-02) protects the four existing legacy Guest/QR activation, deactivation, revocation and restoration transactions. mutateWithQr delegates to the unchanged P4-039 guard, freezing canonical Guest/Event/actor and selected Event identities through the existing three attempts. Its sixth primary locking read selects the current unique QR by guarded Guest ID and checks raw QR identity/parent; cached caller relations supply no authority. The four existing payloads and missing-QR/status behavior remain. The dormant GuestAccess reader below still has no runtime caller and its boolean authorizes no write. No QR generation/file delivery or customer Guest writer is added. Full gate: **2,102 tests / 42,558 assertions**. [Contract and limits](LEGACY_GUEST_QR_WRITES.md).

## HISTORICAL IMPLEMENTATION — P4-039 update/cancel only (2026-11-02)

The separate [LegacyGuestWrites runtime boundary](LEGACY_GUEST_WRITES.md) protects only two existing administrator metadata/cancel transactions for the captured selected unowned Event. It uses fresh locked Event/administrator/offline-lock/Guest context and repeats the allowance floor against current usage. The dormant GuestAccess reader below still has no runtime caller; its boolean supplies no write authority. No customer Guest writer, QR/file protection or general Guest service extraction is added. Full source gate: **2,066 tests / 42,134 assertions**.

## IMPLEMENTED — P4-002 dormant lineage observations (2026-10-22)

[GuestAccess](../app/Services/Tenancy/GuestAccess.php) provides allows(Connection,userId,workspaceId,eventId,guestId,action,qrCodeId=null):bool for future self-service callers. It uses fresh [workspace/event permissions](WORKSPACE_ACCESS.md) and then checks the complete resource lineage on the explicit primary connection.

| Exact action | Delegated event action | QR ID |
|---|---|---|
| guest.view | event.view | Must be absent |
| guest.update | event.update | Must be absent |
| qr.view | event.view | Required positive integer |
| qr.regenerate | event.update | Required positive integer |

The four exact actions are guest.view, guest.update, qr.view and qr.regenerate. Views delegate to event.view; update/regenerate delegate to event.update. Explicit positive PHP integer user/workspace/event/guest IDs and the required/absent QR-ID shape are checked before connecting. A bounded primary join then verifies the exact event/workspace and guest/event lineage; QR actions additionally require the exact QR/guest pair. Safe integer single-column resource primary keys are required. Only identity columns are selected; no names, tokens, codes, counters or paths. Null/unowned/foreign/dangling/malformed parents, same-event QR swaps and unavailable/errors deny privately.

An active owner/manager of workspaceA may observe permitted guest/QR operations in its requested event. That does not authorize a guest in another event/workspace, or QR2 when the URL/context names guest1—even if both guests belong to the same event. A scanner assignment, platform/global role, receipt, QR token or manual code grants no bypass. Unknown actions deny even for owners; no default/session/name context is selected.

No Eloquent/defaultEvent helper, write, lock, transaction, log or file operation runs. Reusing the service performs fresh reads; member/account/parent changes are observed on the next call according to caller isolation. Successful observation does not validate an event lifecycle operation, available admission capacity, fields, offline locks or the safety of a file path.

## IMPLEMENTED — verification

The complete isolated suite passed **1,011 tests / 19,736 assertions** (256.576754s), with zero errors/failures/skips. Focused guest/workspace/authority checks passed **143 tests / 6,978 assertions** (53.414269s), including 35 new guest/QR cases. New-file Pint, both PHP syntax checks, all97 registered route method/path pairs and the 32-table read-only CLI smoke passed.

The first full run completed1011 tests with one existing recovery CLI subprocess timeout (30-second limit), zero assertion failures and no new guest/QR error. That exact production-apply case then passed alone (1 test/18 assertions); the complete suite was rerun without changing source/tests/timeouts or excluding cases. The timeout is recorded as an observed test-environment limit; its underlying cause is not established.

Tests cover both workspace positive controls, same-event and cross-event guest/QR swaps, owner/manager/member revocation, strict pre-connect inputs, primary/read/default traps outside ambient transactions, malformed keys/parents, private failures and complete fixture conservation, including codes/tokens/history. Delegation is checked explicitly because current event view/update permit the same roles. Raw malformed-ID fixtures and SQLite tests do not establish every MySQL coercion/race or deployed behavior.

## REQUIRES VERIFICATION — runtime boundaries

GuestAccess has no runtime caller and adds no controller/policy/route/file delivery, account, schema, write, grant or deployment. Its booleans are point-in-time observations, not mutation authorization or revocation fencing; future writes need fresh locked account/member/lineage checks in the SAME write transaction. Caller isolation is preserved; authority and resource reads are not one atomic snapshot. Existing global-admin endpoints and static QR/files remain unchanged and must be isolated before customer exposure. Bulk/create/delete/import/export/reports/history/notifications/scanner/offline actions, neutral customer identity and actual database/restore/legacy mapping/fencing remain open. No configured customer DB, private files, service, dependency or existing build was changed.

New workspace creators remain the intended responsible owners, with no developer manifest. Unknown legacy event owners remain private to future customer queries. [Plan](PROJECT_PLAN.md), [contract](phases/PHASE_04_AUTHORIZATION.md), [phase report](PHASE_04_REPORT.md).
