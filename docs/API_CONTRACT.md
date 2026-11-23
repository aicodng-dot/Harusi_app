# Kadify API contract

Guest allowance maintenance (2026-11-12): existing Special / Family custom allowances now accept 3–100 entries. Single remains 1 and Double remains 2. Existing QR/manual admission quantity validation accepts 1–100 while enforcing the actual remaining allowance; offline manual used-count input accepts 0–100 and retains the guest allowance checks. Routes, field names, response shapes and database schema are unchanged.

P4-046 changes no route or runtime response. Its [reviewed design](LEGACY_READ_EXPORT_MEDIA_DESIGN.md) retains all 100 existing method/path pairs and proposes current scalar HTML projections, complete CSV/XLSX preparation before byte-only stream callbacks, and authenticated buffered private PNG/ZIP delivery. Intended privacy changes are null-Event log exclusion, bound import-result presentation and private no-store responses. Existing headings, types, filenames, ordinary history and repair/offline behavior stay compatible; actual implementation/selected-engine/host evidence remains P4-047 or rollout work.

P4-047 slice 1 (2026-11-07) implements part of this on the same routes: null-Event rows no longer appear on the selected-Event notification log; check-in rows keep their own stored fields but show "Unknown QR"/blank/"Unknown" instead of a Guest or scanner currently belonging to another Event; check-in search matches only selected-Event Guests; saved import results show only to the actor and Event that produced them; CSV and XLSX bodies are complete before the response is returned. CSV/XLSX headings, column order, types, filenames and formula protection are unchanged. Since the slice 1 review, CSV/XLSX responses also send `Cache-Control: private, no-store, max-age=0` and `X-Content-Type-Options: nosniff`, and a withheld import result shows a generic localized re-upload note (`guest_issuance.result_withheld`).

P4-047 slice 2 (2026-11-08) keeps the same four media routes (`GET /admin/guests/{guest}/qr`, `GET /admin/guests/{guest}/qr/download`, `GET /admin/qr-codes/download-all`, `POST /admin/qr-codes/download-selected`) and adds none. Bytes come only from the private `guest_qr` disk, after the existing guarded repair and a locked check of the complete set; there is no public fallback. Successful responses are complete bodies (Range is ignored; no validators or 304) with `Cache-Control: private, no-store, max-age=0`, `X-Content-Type-Options: nosniff` and a quoted `inline` (preview) or `attachment` (download/ZIP) disposition; PNG, ZIP and member names are unchanged. A foreign or moved Guest stays 404 and actor loss stays 403. The guarded repair probe runs first. Problems it finds (unavailable or unsafe storage, unsafe or shared keys, failed repair) keep the existing localized issuance feedback (`guest_issuance.failure`). Problems found only by the locked delivery recheck (state changed after the repair, empty/null/failed reads, ZIP32 limits) redirect back with the fixed localized `guest_qr_media.failure` (SW/EN). The existing selected-ZIP selection messages are unchanged, and a selected ZIP is refused rather than reduced. Until the private root exists and existing files are copied by the gated cutover ([deployment item 10](DEPLOYMENT_CPANEL.md)), these endpoints fail closed, along with QR generation/repair, QR-issuing Guest creation/import and deletion of Guests that have a QR path. `public/.htaccess` denies `storage/qr-codes` on Apache.

P4-047 slice 3 (2026-11-08) keeps all 100 method/path pairs and the visible layout. The 11 family-3 HTML routes (dashboard, Guest list/detail/edit/create, import GET and preview POST, QR list, check-ins, notification logs, reports) now send `Cache-Control: private, no-store, max-age=0` and `X-Content-Type-Options: nosniff`, including the redirects and validation responses the controller produces. Middleware responses on those routes run first and keep `no-cache, private`: the "select an event" redirect, the 404 for a missing `{guest}` and authentication responses. None of them contains private data. Other admin pages keep their existing headers. If the actor is no longer the exact active administrator when an admin page view is prepared, the page refuses with 403. An unusable selection on catalogue/settings pages simply shows no selected Event.

P4-047 slice 4 (2026-11-09) keeps all method/path pairs, parameters, page sizes, filters, ordering, headings, columns and file names. Family-3 pages, exports and the import preview now read in one attempt. If the selected Event becomes unusable during it, the response is 404 and the selection clears. If the actor is no longer the exact active administrator, the response is 403 with no private rows read and no preview stored. A Guest moved to another Event refuses with 404 on its detail and edit pages. Check-in name and phone search matches only Guests currently in the selected Event. A failure that is not SQL refuses with the fixed administrator read failure. Writers to that Event's rows wait while an attempt runs.

P4-045 retains100 method/path pairs, ordinary validation/redirects/messages and existing scanner/export payloads. The same create/import/generation/repair callers now use current authority. Known rolled-back row failures retain generic failure/continuation; authority/offline failures propagate, and database/rollback/commit uncertainty interrupts with fixed private feedback rather than false import completion. Import previews bind and consume captured actor/Event context, with explicit sequential-session replay limits. Narrow SW/EN failure/interrupted labels preserve global locale. No customer endpoint, ordinary-media serving migration or new API envelope is added. Full gate: **2,288 tests / 44,727 assertions**. [Exact behavior](LEGACY_GUEST_ISSUANCE.md).

Historical P4-043 checkpoint (2026-11-03): P4-043 keeps all 100 method/path pairs, normal single/bulk redirects and existing success strings. Current actor loss returns 403; current selected/Guest/QR lineage loss returns 404; offline browser/JSON feedback remains. False storage, cancelled/null model deletion, ambiguous paths and sanitized operational failures redirect back with guest_deletion.failure in the supported account language, falling back to SW. Global locale stays unchanged; this is HTML feedback even when JSON is requested. No customer endpoint or API envelope is added. Full gate: **2,166 tests / 43,324 assertions**. [Contract](LEGACY_GUEST_DELETION.md).

Historical P4-044 design checkpoint (2026-11-03): the whole-family issuance contract was reviewed as documentation only, with P4-043 retained by source preservation and no backend rerun. Runtime acceptance arrived separately in P4-045; retain the [reviewed design](LEGACY_GUEST_ISSUANCE_DESIGN.md) as historical scope and dependency evidence.

Historical P4-040 checkpoint (2026-11-02): P4-040 preserves all 100 method/path pairs, four Guest/QR payloads, preflight checks, redirects and messages. Activate/deactivate still return 404 for missing QR; revoke/restore still succeed without one. Deactivate retains revocation time; activate/restore clear it; restoration retains current usage/status rules. Late actor/context loss remains 403/404 and offline locking keeps browser/JSON feedback. No customer endpoint is added. Full gate: **2,102 tests / 42,558 assertions**. [Contract and limits](LEGACY_GUEST_QR_WRITES.md).

Historical P4-039 checkpoint (2026-11-02; update/cancel only): P4-039 preserves all 100 method/path pairs and existing single-Guest update/cancel entry checks, validation, phone normalization, pass mapping, allowlists, redirects and success messages. The same allowed_entries feedback now also protects admissions arriving after initial validation. Current actor loss returns 403; invalid/unowned-context loss returns 404. A late blocking offline Session retains the existing browser error redirect or JSON 423 message. No customer endpoint or response envelope is added. Full source gate: **2,066 tests / 42,134 assertions**. [Named writes and limits](LEGACY_GUEST_WRITES.md).

The 2026-10-30 PangaEvents 39-screen reference update adds no endpoint or behavior. Existing route/view analogs and missing contracts are explicit in the [screen matrix](REFERENCE_UI_SCREEN_MATRIX.md); planned target paths are not active APIs. Secure guest access, truthful delivery, authoritative admissions and tenant permissions remain prerequisites. See [39-screen implementation](REFERENCE_UI_IMPLEMENTATION.md).

P4-038 preserves100 routes, existing destroy validation/success shapes, late403/404 passthrough and generic storage/SQL feedback. Protected cancellation or invalid persisted marker/audit cannot return success; unchanged direct helpers remain trusted composition. Named SQL partial outcomes and changed after-write callback rollback windows are explicit. Full source gate: **2,028 tests / 41,407 assertions**. [Contract](OFFLINE_SECURE_DELETE_BOUNDARIES.md).


Historical P4-037 design checkpoint (2026-10-29): P4-037 changes no endpoint, response or runtime authority. The planned later-write providers must preserve existing success/generic feedback, refuse invalid current context and cancelled/unpersisted writes, and keep retention as a byte-only rule. New callback/commit failure windows need explicit future tests. P4-036 remains the implemented source gate. [Design](OFFLINE_SECURE_DELETE_BOUNDARIES.md).


Historical P4-036 byte-only checkpoint (2026-10-29): P4-036 preserves all100 routes, request validation, successful redirects and the existing destroy/controller API shapes. Both destroy actions now pass late HttpExceptionInterface403/404 through before generic RuntimeException handling. Storage/SQL failures retain “Unable to delete encrypted offline scanner file.” Only secure-delete bytes receive the new current-authority fence; later metadata/audit authority remains open. Full source gate: **1,937 tests / 37,724 assertions**. [Contract](OFFLINE_SECURE_DELETE_BOUNDARIES.md).


P4-034 preserves all100 routes and existing upload/controller APIs while adding bounded known root callback retry cleanup. Positive path references and unsuccessful cleanup stop before private file access/new puts as applicable; ordinary caught failures retain generic feedback. Current authority loss remains404/403. [Contract and limits](OFFLINE_RETRY_ARTIFACT_RECOVERY.md).


Canonical reference; source and `artisan route:list --json` verified through 2026-10-30. Current route count is 100; the Phase0/1 baseline was 88. Registry: [snapshot](CURRENT_ROUTE_REGISTRY.md).

## CURRENT — compatibility

P4-030/P4-031 preserve all100 routes and upload success/validation shapes. The selected Event and validated envelope must match captured Session context; current Event/administrator/Session checks run before both preliminary audits and preview writes. Canonical head/ownership mismatch is generic404 and current actor loss403 through the existing preview catch. The established invalid raw locked Session Event validation message remains unchanged. Authorized invalid-file traces and internal duplicate/lifecycle validation behavior are retained. No endpoint or recovery guarantee is added. [Boundaries](OFFLINE_UPLOAD_FILE_EFFECTS.md).

P4-032 historically changed no endpoint, response or error-handling contract; its TEMP probe reproduced earlier retry artifacts with P4-031 as the then-current source gate. P4-033 reviewed the design. P4-034's bounded preview retry source gate was **1,901 tests / 36,878 assertions**. It changes no success or validation response shape. A failed cleanup or positive path-reference veto uses the existing broad-catch message, “Unable to verify the Android scanner results file.” Storage exceptions are wrapped so concurrency-shaped storage messages cannot trigger another put; private paths and injected markers stay out of feedback. Unmarked earlier commit-class artifacts and same-attempt uncertain commit recovery remain outside this guarantee. [Evidence](OFFLINE_UPLOAD_FILE_EFFECTS.md).

P2-004 adds the three /api/v1/auth endpoints in routes/api.php using Sanctum bearer authentication. Website/scanner continue to share session web auth. Scanner JSON requires scanner role and CSRF. Preserve existing scanner response shapes when extracting services.

| Method/path family | Behavior and permission | Detail |
|---|---|---|
| GET/POST `/login`; GET `/admin/login`, `/scanner/login`; POST `/logout` | Shared email-or-phone/password; entry labels grant no role; login10/min independently per IP/canonical identifier; persisted active-account checks | [Authentication](AUTHENTICATION_ARCHITECTURE.md) |
| GET/POST `/forgot-password`; GET `/reset-password/{token}`, POST `/reset-password` | Public web recovery/reset with CSRF; registered-email delivery, generic request response, independent IP/identity limits | [Recovery](ACCOUNT_RECOVERY.md) |
| GET `/customer` | Session and exact customer role; own active workspace memberships only, paginated read-only HTML | [Customer identity](CUSTOMER_WEB_IDENTITY.md) |
| GET `/customer/workspaces/create`; POST `/customer/workspaces` | Existing active customer setup; automatic creator-owner, CSRF, exact retry and account/IP throttle; localized HTML | [Setup](CUSTOMER_WORKSPACE_SETUP.md) |
| `/admin/events*` | Unowned-only legacy catalogue/direct metadata controls; owned targets404; offline create/store entry checks also require unowned context; downstream offline fences remain pending | [Snapshot](CURRENT_ROUTE_REGISTRY.md), [tenancy](MULTI_TENANCY.md) |
| `/admin/users*` | Legacy staff management; customer/unsupported/workspace-linked/platform targets excluded | [Roles](ROLES_AND_PERMISSIONS.md) |
| `/admin/guests*`, `/admin/qr-codes*` | Fresh unowned selected-event gateway/current context; since P4-047 slice 2, PNG/ZIP bytes only from the private `guest_qr` disk with private no-store/nosniff full-body responses (see above); remaining read-bundle and caller/commit limits stay pending | [Audit](CURRENT_SYSTEM_AUDIT.md), [design](LEGACY_READ_EXPORT_MEDIA_DESIGN.md) |
| `/admin/checkins*`, `/admin/reports*`, `/admin/notification-logs` | Event history/CSV/reports; since P4-047 slice 1, selected-Event notification logs only (null-Event rows excluded) and history decoration limited to selected-Event Guests/scanners | [Audit](CURRENT_SYSTEM_AUDIT.md), [design](LEGACY_READ_EXPORT_MEDIA_DESIGN.md) |
| GET `/scanner/dashboard`, `/scan`, `/manual-search`, `/recent-scans` | Authenticated scanner/assigned event | [Scanner](SCANNER_ARCHITECTURE.md) |
| POST `/scanner/validate`, `/scanner/verify` | JSON validation; 180/min; offline lock | [Scanner](SCANNER_ARCHITECTURE.md) |
| POST `/scanner/admit`, `/scanner/manual-admit` | Transactional admission; 60/min; offline lock | [Scanner](SCANNER_ARCHITECTURE.md) |
| GET `/scanner/ticket/{token}`, `/scanner/verify/{token}` | Scanner-only pass page | [Scanner](SCANNER_ARCHITECTURE.md) |
| `/admin/events/{event}/offline-scanner*`, `/admin/offline-scanner/*` | Fresh selected unowned parent entry before resource policy/payload/validation/effects; existing lifecycle policies and operation throttles | [Parent checks](LEGACY_OFFLINE_RESOURCES.md), [sync](OFFLINE_SYNC.md) |
| GET/POST `/webhooks/whatsapp/meta` | GET subscription verification; POST ack stub | [WhatsApp](WHATSAPP_INTEGRATION.md) |
| `/up`, `/storage/{path}` | Framework health/signed local-file handling | Snapshot/filesystem config |

Shapes vary: HTML redirects/flashes; Laravel JSON validation `message`/`errors`; scanner-specific status payloads; webhook `ok`/`provider`; 419 JSON only `message`. Do not silently add a universal envelope. 403/404 protect resources, unauthenticated browser requests redirect (JSON can return 401), CSRF uses 419, validation 422, throttles 429, offline admission locks use 423 and scanner business-state conflicts use 409. Offline import conflicts are stored for review through browser redirect flows. Exact scanner codes are in its specialist contract.

List pages use Laravel pagination, typically 15/25 items. There is no universal JSON pagination or online admission request-key idempotency.

**IMPLEMENTED P2-002 input contract:** POST /login accepts string identifier (max255) and password; when identifier is absent, legacy email is accepted. Explicit identifier takes precedence if both are submitted. Invalid/unknown/inactive/unsupported-role credentials use the same generic validation message under the submitted identity field; browser redirects/flashes and JSON422 message/errors remain compatible. Disabled/deleted authenticated web accounts receive browser login redirect or JSON401 `{message:"Unauthenticated."}`. The separate P2-004 bearer API is described below.

**IMPLEMENTED P2-003:** POST /forgot-password accepts identifier (or email when identifier is absent). Browser requests redirect to password.request with the generic status; JSON200 returns {"message":"If a matching account can be recovered, a password reset link will be sent to its registered email."}. Unknown/malformed/inactive/unsupported input gets the same response. POST /reset-password requires token, email, password and password_confirmation (minimum eight characters); success redirects to login with status, never authenticates automatically. Invalid/expired/replayed tokens use generic email validation errors (JSON422); ordinary validation retains Laravel message/errors. Each POST route permits10/min independently per IP and normalized identity; reset keys use email regardless of unrelated identifier input. CSRF returns419, throttling429. Old browser/remember access is rejected after a password change. P2-004 additionally revokes account device tokens within successful reset transactions.

## IMPLEMENTED — P2-004 device authentication

All endpoints require HTTPS; plain HTTP returns426 without redirect, including localhost. Responses use Cache-Control:no-store,private and Referrer-Policy:no-referrer. Errors remain JSON without an Accept header. Browser cookies and remember sessions are never accepted for API authentication; bearer tokens never authenticate existing web/scanner routes.

| Method/path | Inputs and success | Authentication |
|---|---|---|
| POST /api/v1/auth/login | identifier (max255; legacy email when identifier absent), password, device_name (max100). JSON201 returns success:true, message:"Signed in.", data:{user,access_token,token_type:"Bearer",expires_at} | Shared account credentials;10/min independently per IP and normalized identity |
| GET /api/v1/auth/me | JSON200 returns success:true, message:"Authenticated.", data:{user} | Real bearer token with auth:read; active identity and active explicit scanner event |
| POST /api/v1/auth/logout | JSON-free204; deletes only this token | Real bearer token with auth:logout and active identity; allowed if scanner event became unavailable |

The user allowlist is id,name,email,phone_number,preferred_language,role,event_id,gate_name. No password hash, remember token, token relation or arbitrary model fields are serialized. Login returns the plaintext secret once; only its SHA-256 hash is stored. Issued abilities are auth:read/auth:logout, expiry is24hours with no sliding/refresh, and each account retains at most10 unexpired tokens (oldest revoked on new login). Device names convey no authorization.

Errors are {success:false,message,errors:{}}; validation422 has field arrays. Missing identifier/email reports identifier. Bad/unknown/inactive/unsupported credentials use one401 message. Missing/invalid/expired/revoked token or unavailable account returns401, missing ability or lost event authorization returns 403, and throttling429 retains Retry-After. Unknown routes/methods use404/405; unexpected exceptions use a generic500. The API never exposes exception text. Existing scanner envelopes stay unchanged.

Recovery revokes all tokens inside its transaction. Administrative credential/availability/role/event/gate changes do likewise; deleting an account removes its tokens. Current-device API logout leaves other tokens and browser sessions intact. No guest/admission/tenant/Android-client endpoint is delivered here. Full isolated regression passed **353 tests / 3,049 assertions** (124.386315s), including **48 tests / 875 assertions** in DeviceTokenAuthenticationTest. New-file Pint, 15 PHP syntax checks and scoped whitespace checks passed. Runtime registers97routes: the prior94 plus the three versioned auth endpoints; no Sanctum CSRF-cookie route is enabled. Tests use SQLite memory, fake notifications/HTTP and temporary Laravel storage. Existing public/build, .env and unrelated checked source are preserved.

## IMPLEMENTED — P4-028 sibling integrity validation (2026-10-28)

The same resolve POST refuses a preexisting inconsistent OPEN sibling through generic conflict_resolution validation before Guest/action effects (browser error redirect; JSON422). Sibling updates use a frozen validated set with repeated parent/status predicates. Null-Log and escalation behavior, prior authorization403/404 and normal messages remain intact. New/unvalidated OPEN children keep readiness pending. No route or envelope addition. [Details](OFFLINE_SYNC.md).

## IMPLEMENTED — P4-027 Guest reference validation (2026-10-27)

The same resolve POST preserves existing conflict_resolution/replacement_guest_id messages and browser/JSON validation behavior. Ambiguous Guest IDs are refused before numeric lookup, and moved Guest/replacement models are not hydrated outside the current Event. Valid canonical MAX IDs, pass capacities, manual adjustment and null-Guest actions remain supported by the same admission backend. Missing-record exception behavior is retained; no route or envelope addition. [Details](OFFLINE_SYNC.md).

## IMPLEMENTED — P4-026 resolver reference validation (2026-10-27)

The same resolve POST now refuses invalid current context or a non-null bound Log outside its Import/Session before resolution effects. These use the existing conflict_resolution validation channel (browser error redirect; JSON422), with generic text and no foreign IDs/details. Literal-null Log actions, normal resolution messages/redirects and P4-025 authorization403/404 remain intact. No route/envelope addition; all100 method/path pairs remain unchanged. [Details](OFFLINE_SYNC.md).

## IMPLEMENTED — P4-025 resolution compatibility (2026-10-27)

The existing resolve POST retains its entry Gate, validation, success message and redirect. A captured identity read plus current locked Event/admin/Session/Import/Conflict checks now precede the unchanged resolver. Late ownership/head mismatch is generic404 and lost administrator eligibility403, explicitly passed through the runtime catch. A resolved Conflict retains existing HTTP403. No route or envelope addition; all100 method/path pairs remain unchanged. Bound-log/Guest/sibling behavior is a subsequent boundary. [Details](OFFLINE_SYNC.md).

## IMPLEMENTED — P4-024 confirmation compatibility (2026-10-27)

The existing confirmation POST keeps its entry Gate, confirm_import validation, success messages and redirect. A locked current Event/admin/Session/Import check now precedes reconciliation: late ownership/parent mismatch is generic404 and late administrator loss is403, passed through instead of converted to runtime-error redirects. Ordinary completed HTTP confirmation remains403; a correctly guarded internal completed retry remains a no-op. No route or response-envelope addition; all100 method/path pairs are unchanged. [Details](OFFLINE_SYNC.md).

## IMPLEMENTED — P4-023 scanner review compatibility (2026-10-27)

All 100 method/path pairs remain unchanged. Authorized setup/create, Session show, result show/logs/conflicts and conflict-detail GET data now explicitly project current scanner relations only to ordinary same-Event scanner accounts. Canonical historical scanner FK/filter IDs and captured names/gates/device data remain intact when a current account is unavailable; only unsafe noncanonical scanner FKs are masked. Inactive local history stays readable while new-package active-only choices remain unchanged. Existing response shapes, navigation, filters and policy behavior remain compatible; no endpoint, grant, authentication mode or scanner envelope is added. Full regression: 1,764 tests / 33,752 assertions. Actor policy, internal returns and authority/concurrency fences remain separate. [Review scope](OFFLINE_SYNC.md).

## IMPLEMENTED — P4-022 Guest review compatibility (2026-10-26)

All 100 method/path pairs remain unchanged. Existing authorized result show/logs/conflicts and conflict-detail GETs now project current Guest/replacement/final-state relations within canonical matching Event/Import/Session context. Unsafe/unavailable current references and unattributed stored server-state fields are hidden in memory; valid own-Event history remains visible after movement/deletion. Stored rows/private files, response shapes, navigation and policy behavior remain intact. This introduces no endpoint, grant, authentication mode or scanner envelope. Full regression: 1,748 tests / 29,947 assertions. Internal return graphs, scanner/actor projections and broader authority/history remain separate. [Review scope](OFFLINE_SYNC.md).

## IMPLEMENTED — P4-021 confirmation compatibility (2026-10-26)

The same 100 method/path pairs remain registered. Confirmation validates canonical raw locked Session/Import Event IDs and equality before processing writes, using existing offline_results validation feedback on failure. Processing Guest models/current conflict state and final-count/admission targets are Event-scoped; moved exported identities retain WRONG_EVENT with null current Guest/state/count fields. Existing successful redirects, closed-session HTTP403 and the internal completed-import no-op remain compatible. No route, authentication mode or scanner envelope changes. Full regression: 1,732 tests / 28,538 assertions. At this gate, returned/review eager graphs and old stored state were deliberately unchanged; P4-022 subsequently protects the named HTTP Guest review slice while internal returns and stored repair remain separate. [Confirmation scope](OFFLINE_SYNC.md).

## IMPLEMENTED — P4-020 preview/review compatibility (2026-10-26)

All 100 route method/path pairs remain unchanged. New result previews no longer bind foreign current Guest models/state; exported moved identities retain WRONG_EVENT with redacted current fields. Review log rows/search reject unsafe numeric aliases while preserving canonical MAX and name/manual-code lookup. An invalid raw locked Session Event uses existing offline_results validation feedback before preview rows/file creation; the preliminary upload audit remains. No new API, authentication mode or scanner envelope is introduced. Full regression: 1,720 tests / 28,050 assertions. Historical stored rows and later confirmation/resolution remain separate boundaries. [Preview contract](OFFLINE_SYNC.md).

## TARGET — new API standards

- New APIs prefer `/api/v1`; record auth URLs before implementation. Suggested `/api/auth/*` is not an existing contract.
- HTTPS bearer tokens must be hashed server-side, device-labelled, expiring/revocable, and securely stored on Android. Token possession still requires active account/event authorization. Web keeps sessions/CSRF.
- GET reads; POST creates/actions; PATCH updates; DELETE revokes/deletes. Never introduce GET side effects. Resolve/authorize tenant context server-side.
- Success: `{"success":true,"message":"Operation successful","data":{}}`. Error: `{"success":false,"message":"Request could not be completed","errors":{}}`. Avoid secrets, traces and tenant enumeration.
- Status: 200 read/update; 201 create; 204 bodyless delete; 400 malformed; 401 invalid/missing auth; 403 forbidden; 404 absent/hidden; 409 state/idempotency conflict; 422 validation; 429 throttle; 503 temporary dependency failure.
- Collections: `data`, `meta` (`current_page`, `per_page`, `total`, `last_page`), `links`; bound page size, allowlist sort/filter and scope all queries.
- Throttle auth by IP and canonical identifier without revealing accounts; measure scanner capacity before changing its limits. Return retry guidance; never place device/session credentials in URLs or any secret in logs. The planned invitation URL below is a separate narrowly scoped guest capability with explicit URL-log redaction requirements.
- Admission/sync/delivery mutations require operation IDs or `Idempotency-Key`, persisted outcome/request fingerprint and defined retention. Changed body with same key is a conflict. Locks still enforce capacity.
- Add compatible optional fields; version incompatible meaning/shape changes; add contract tests before deprecation. Keep web scanner operational.

## PLANNED — additions

P2-004 authentication endpoints are implemented in source/tests; [execution details](phases/PHASE_02_AUTHENTICATION.md). P2-005 cleared the reported dependency advisories; the same 97 routes and existing API contracts pass on Laravel 12.70.0. Tenant APIs follow authorization foundations; invitations follow engine/schema. Offline file protocols retain their own versioning. Proposed contracts below remain PLANNED; mark them CURRENT only after implementation and verification.

## PLANNED — customer invitation and RSVP contracts

Requirement received 2026-10-25; reviewed 2026-10-26. None of these routes is registered. Retain the existing `/customer` session architecture rather than introduce a competing `/portal` identity. Let `{base}` mean `/customer/workspaces/{workspace}/events/{event}`; every operation authenticates an active customer, checks current membership and operation permission, and validates every resource's exact workspace/event lineage. Mutations repeat relevant checks within the transaction; queued jobs recheck at execution. The [RSVP/ticket specialist](RSVP_AND_GUEST_TICKETS.md) owns token, transition and persistence details.

| Proposed method/path | Input/output contract | Gate |
|---|---|---|
| GET `/customer/workspaces/{workspace}/events/create`; POST `/customer/workspaces/{workspace}/events` | Category then minimal names/date-time/venue; POST persists the authoritative event through EventService with a retry key and explicit timezone, then redirects toward its saved preview | P9-UX-01/02; full preview also requires P16/P17 |
| GET `{base}/setup`; PATCH `{base}` | Resume saved setup / validate allowlisted edits with expected revision; never accept ownership from the form | Event contracts and tenant policies |
| GET `{base}/invitations`; POST `{base}/invitations`; PATCH `{base}/invitations/{design}` | View saved preview / select a template and persist design / Quick Edit validated fields; bind real event data and a validated design revision | P16-UX-01/P17-UX-01 |
| POST `{base}/guests/{guest}/invitation-access` | Explicitly issue or rotate a personal invitation capability; return the link only to the authorized caller, create pending response on first issue, preserve existing response on rotation | P19-UX-01; no capability issuance on GET |
| POST `{base}/invitations/send` | Confirmed bounded recipient IDs, design revision, approved message template/language and operation key; verify consent/usage and persist a durable batch before dispatch | P24-UX-01 |
| GET `{base}/invitation-batches/{batch}` | Authorized progress page; a documented JSON polling representation returns selected/queued/processing/outcome_unknown/completed counts, send outcomes, delivery evidence and paginated per-guest statuses | P24-UX-02 |
| GET `{base}/rsvp-summary` | Independent server aggregates: issued, attending, not_attending, pending; separately labelled messages and admissions, with scope/as-of time | P19-UX-04/P24-UX-03 |
| GET `/invite/{token}` | Private guest page, approved invitation/event projection and RSVP/ticket eligibility; no account or business mutation | P19-UX-01 |
| POST `/invite/{token}/rsvp` | response attending or not_attending, expected revision and submission key; CSRF plus capability and event rules; persisted receipt, safe retry and immediate localized confirmation | P19-UX-02 |
| GET `/invite/{token}/ticket` | Only this invitation's existing authorized pass; same guest/QR/event, allowed count, optional table and manual code; no generation, admission or usage mutation | P19-UX-03 |

These are HTML session flows, with CSRF for browser mutations; the public capability does not become a customer or device session. GET200 reads and successful HTML mutations redirect with 303 to the resulting page; the send POST redirects to its durable batch progress page. If JSON is explicitly supported, use the new-API envelope: event/design creation201, queued batch202 with its progress URL, response/update200. Decide each route's supported representation in its implementing task; do not change existing HTML/scanner responses or imply an unbuilt JSON API.

Use generic404 for inaccessible parents and invalid/expired/revoked/mismatched guest capabilities; 409 for stale revision, closed response policy or changed payload under a reused operation key; 422 for allowlisted validation; 419 for CSRF; 429 with Retry-After for throttling; safe503 for unavailable dependencies. Missing/inactive owner sessions retain the existing browser authentication behavior. Exact replay returns the existing authorized result/current response without another batch or response change. A receipt or known batch ID is never authority. Empty/foreign recipient selections cannot start a batch. No long blocking provider-send loop runs inside HTTP.

Guest page, ticket and authorized media use HTTPS, private/no-store, no-referrer and noindex; capability hashes alone are stored for lookup. Redact raw capability URLs at application/proxy/host boundaries, avoid third-party requests and protect ticket downloads against public-storage bypass. Rate limits cover public reads and IP-plus-hashed-capability writes; numeric limits must be recorded/tested before exposure. The guest link never contains a sequential guest ID or an existing admission/device token. GETs do not record RSVP, issue/repair QR/manual codes or mark a message delivered. Sent means provider acceptance with evidence; delivered requires authenticated matched webhook evidence. Existing `/scanner/ticket/{token}` routes remain scanner-authenticated and unchanged.

## IMPLEMENTED — P3-013 administrator CSV compatibility (2026-10-20)

All checkin/report CSV output now uses one private writer. Formula-looking ASCII/fullwidth strings, formula prefixes after whitespace/control characters and leading C0 control bytes (0x00–0x1F) receive a TAB prefix in the exported quoted cell. This includes international +phone values. Ordinary Unicode/text, numeric counters, headers, column order, selection/filtering, filenames and MIME remain compatible. An explicit empty fputcsv escape preserves standard quote/comma/backslash/newline round-trip; stored guest/QR/manual-code/history values do not change. Typed XLSX export is unchanged. Raw parsed CSV consumers receive the documented leading TAB on guarded text. No new route or API envelope was introduced. [Exact format and client limits](CSV_EXPORTS.md).

## IMPLEMENTED — P3-018 existing identity transaction evidence (2026-10-21)

Selected synthetic MySQL HTTP-kernel reset/reset and reset/device-login orderings passed with preserved current 302/422/201/401 responses and no API changes. An earlier valid login can commit a token that a subsequent reset revokes; a login serialized after reset rejects the old password. This is not a deployed-browser/SMTP/device or exhaustive concurrency guarantee. [Scope](MYSQL_RECOVERY_REHEARSAL.md).

## IMPLEMENTED — P3-022 endpoint boundary at completion

[Automatic workspace provisioning](WORKSPACE_PROVISIONING.md) is an internal dormant service only. All 97 registered method/path pairs and existing session/token responses remain unchanged. Future setup endpoints must derive creator identity from authentication and enforce tenant policies; request keys/result receipts are not authorization. No public registration/customer route is exposed.

## IMPLEMENTED — P4-001 endpoint boundary at completion

[Workspace/event access observations](WORKSPACE_ACCESS.md) add no route or response change. All 97 method/path pairs remain compatible. Existing global administrator endpoints are still global and must be scoped before customer event exposure; the new internal boolean is not a bearer permission or write fence.

## CURRENT — P4-002 has no endpoint

[Guest/QR lineage observations](GUEST_ACCESS.md) add no HTTP route or response change. Existing admin/scanner routes and static/private file behavior remain unchanged. A successful internal observation is not a token, file-path check or mutation fence.

## IMPLEMENTED — P4-003 compatibility

GET /customer (customer.home) is the only added route (98 total); it uses web session/exact customer role and private no-store HTML. Customer sessions use existing shared login/recovery/remember flows and safe /customer intended paths. Staff/scanner areas deny customers. Device token login/me/logout explicitly exclude the customer mode; legacy bearer responses/abilities remain compatible. Legacy staff user index/CRUD now excludes customer/unsupported targets (404 before validation and on locked write target). No customer signup or mutation endpoint exists. [Contract](CUSTOMER_WEB_IDENTITY.md).

## IMPLEMENTED — P4-004 direct event metadata compatibility

Same 98 route method/path pairs. Catalogue/layout omit workspace-owned events; edit/update/GET-or-POST select/POST-or-PATCH archive/cancel deny owned targets with 404 before validation/navigation. Ordinary unowned forms/redirects and ignored ownership input remain compatible. Update/archive/cancel recheck null ownership under lock. Selected-event/offline/child/file routes remain separate pending boundaries. [Contract](LEGACY_EVENT_ADMINISTRATION.md).

## IMPLEMENTED — P4-005 selected/direct entry compatibility

Same 98 method/path pairs. Invalid explicit selected keys (including null) clear selected/import context and redirect to the catalogue without fallback; only an absent key can auto-select one safe active legacy event. Current-event/import rechecks404 after lost/owned context without default creation. Direct offline create/store return 404 for private/unsafe raw targets before validation or context effects. Same-event imports remain, changed-event imports are discarded. These entry checks do not certify downstream writer/file/parent-lineage fencing. [Contract](LEGACY_SELECTED_EVENT.md).

## IMPLEMENTED — P4-006 staff management compatibility

Same 98 method/path pairs. Legacy staff list/edit/update/delete exclude workspace-linked/platform accounts and unsafe/missing/owned assigned parents, including for privileged legacy actors. Invalid event assignment or ambiguous empty scanner assignment produces a generic event_id validation error; no default event is created. Credential/account mutations lock parents before User and reread current target/membership before writing. Ordinary eligible unowned staff management remains compatible. [Contract](LEGACY_STAFF_ACCOUNTS.md).

## IMPLEMENTED — P4-009 HTML setup compatibility

Two customer setup routes bring the registry to 100. Existing 98 method/path pairs are preserved. Successful creation/replay 302 home; input 422, conflict 409 with fresh form key, transient/prerequisite 503 with retained key, locked unavailable creator 403, named account/IP throttle 429. These controller responses are HTML/private even when JSON is requested; existing framework auth/CSRF JSON401/419 remain compatible. No bearer endpoint or scanner envelope changed. [Exact contract](CUSTOMER_WORKSPACE_SETUP.md).

## IMPLEMENTED — P4-010 offline compatibility

Same 100 route method/path pairs. Private/foreign/malformed/inconsistent offline heads now return 404 before controller resource policies, payloads and effects. Preview session shape failures remain 422; an exact private/foreign/missing session is hidden before file validation. Generated-package session mismatch is hidden before form validation. Existing allowed HTML/redirect/lifecycle flows and scanner contracts remain intact. These are entry checks, not complete downstream writer/file fences. [Scope](LEGACY_OFFLINE_RESOURCES.md).

## IMPLEMENTED — P4-011 package-generation compatibility

Same 100 route method/path pairs and existing package/envelope formats. Private/ineligible master/client IDs fail generic existing field validation. Package generation rechecks current unowned Event and actor/scanner eligibility inside its transaction; late parent 404 / actor 403 propagate, while invalid-scanner business feedback remains compatible. SQL failures return generic feedback and retain non-password inputs. Later postcommit download/file and offline actions are separate fences. [Scope](LEGACY_OFFLINE_PACKAGE_GENERATION.md).

## IMPLEMENTED — P4-013 locked session cancel/close (2026-10-23)

Existing administrator cancel/close effects now recheck the current unowned Event, eligible actor, exact Session parent and lifecycle conditions under the transaction locks. Immediate import parents and current imported/open-conflict state are checked before effects. Audit-write failures roll back and return generic feedback; internal generation/import transitions remain unchanged. Full source gate: **1,482 tests / 24,782 assertions**. Actual MySQL action schedules and other offline/file/postcommit paths remain separate. [Scope](LEGACY_OFFLINE_SESSION_ACTIONS.md).

## IMPLEMENTED — P4-014 locked package delivery (2026-10-23)

All three existing download paths now lock current unowned Event/actor/Session/Package rows through fully buffered file reading, metadata/audit and transaction completion. Current-export downloads recheck the pointer; historical downloads retain compatibility. Precommit delivery failures roll back delivery metadata/audits while preserving already-committed generation. Full source gate: **1,565 tests / 25,642 assertions**. File deletion, integrity, client receipt, commit uncertainty and actual delivery engine schedules remain separate limits. [Scope](LEGACY_OFFLINE_PACKAGE_DELIVERY.md).

## IMPLEMENTED — P4-015 archive metadata (2026-10-24)

Package/ResultImport archive metadata and audit now share current Event/actor/Session/resource checks and a transaction. The locked current parent is supplied to existing helpers; no file bytes are accessed or changed. Existing archive eligibility and history compatibility remain. Full source gate: **1,653 tests / 26,523 assertions**. Secure deletion, other offline/file actions and actual archive engine schedules remain separate. [Scope](LEGACY_OFFLINE_FILE_ARCHIVES.md).

## IMPLEMENTED — P4-019 import/conflict database feedback (2026-10-25)

The result-confirm and conflict-resolve service-call blocks now catch PDOException before their existing business-error unions. Local QueryException, direct PDO and typed DeadlockException failures receive stable action-specific form errors, keeping driver/SQL/binding/private-path details out of those errors. ValidationException handling, existing InvalidArgumentException/RuntimeException/LogicException business messages, error keys, redirects and successes remain unchanged. P4-018 deletion feedback is preserved.

The complete isolated backend suite passed **1,684 tests / 27,184 assertions** (280.096104s), zero errors/failures/skips. Focused offline/staff checks passed **583 tests / 5,840 assertions** (89.289556s). The new 10-case class contributes 277 assertions. Four old-code SQL disclosure cases passed their state observations before failing feedback assertions: 4 tests / 70 assertions / 4 failures / 0 errors. All 73 prior classes retain exact counts/assertions. Three scoped syntax checks and read-only Pint, unchanged 100 routes/32-table safe CLI, 336 tested source fingerprints, 416 baseline paths outside the declared two controllers/docs, environment/app key, Git index/build and local links passed. Normalized whole-controller comparison permits only the PDO import and catch insertions; service/transaction/file and all existing test code remain preserved.

The four real SQLite TEMP-trigger cases use legitimate encrypted preview and drift-conflict fixtures: first Import status UPDATE, final import audit INSERT, conflict-resolution UPDATE after admission mutations, and conflict audit INSERT. Every compared table row and private fake-disk file hash is checked before feedback. Failures restore the caller state through existing level 2→1 savepoint rollback. The later three faults observe completed mutations before rollback. Four direct PDO/typed-deadlock faults occur before completed DML and verify safe feedback only; two InvalidArgumentException controls retain their business messages and conserve compared state.

These changes sanitize PDO-class failures escaping the two existing service calls. Binding, preflight and request-validation SQL failures outside those blocks remain framework-handled; business RuntimeException messages are deliberately preserved. Controlled SQLite savepoints do not establish outer-commit certainty, real MySQL deadlock recovery, network/crash outcomes, every concurrency schedule or a globally deadlock-free graph. No import/resolver transaction, retry, lock, file, policy or audit behavior changed. Current authority fences, preliminary preview audits, nested history/path integrity, secure-delete recovery and unknown commit outcomes remain separate. No schema/account/ownership/dependency/build/environment/app-key or configured data/service change or deployment occurred. Customer event/signup, actual database/restore/legacy ownership/fencing and browser/device/hosting/SMTP remain rollout gates. Phase4 remains IN PROGRESS. [Plan](PROJECT_PLAN.md).

## Phase4 surface inventory - P4-041 (2026-11-02)

The documentation-only exit audit preserves the 100 registered method/path pairs. It maps existing create/import/QR repair, Guest deletion, reports/downloads and scanner routes to four finite authorization families. Scanner token GET aliases remain authenticated scanner pages; public guest invitation/ticket endpoints remain planned. Meta POST remains an acknowledgment stub without status writes; signed processing belongs to P24. [Finite Phase4 exit register](PHASE_04_EXIT_AUDIT.md).

## Historical design - P4-042 deletion feedback

No current route or response changed. The reviewed implementation target preserves normal single/bulk redirects and success messages, 403/404 authority refusals and existing offline browser/JSON feedback. New false/cancel/ambiguous-path and sanitized operational failures will redirect with a generic localized error rather than claim deletion success or expose private diagnostics. Use narrow guest_deletion.failure SW/EN resources and validated account preference with SW fallback; keep global locale and normal legacy success text unchanged. [Deletion design and observed outcomes](LEGACY_GUEST_DELETION_DESIGN.md).
