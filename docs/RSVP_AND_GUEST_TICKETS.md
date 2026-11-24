# RSVP and guest tickets

> **Implementation note (2026-11-24):** RSVP is now implemented on the Sharing Center personal link (`/i/{key}`) with the `existing_pass` ticket policy. Decisions taken, schema and evidence: [project plan — RSVP](PROJECT_PLAN.md#rsvp--2026-11-24-p19-ux-02--p19-ux-04-scope-on-the-sharing-center-link). Differences from this design: the link key is stored encrypted (for re-copying) besides its SHA-256 lookup hash; links have no expiry (they stop with QR revocation/replacement, guest cancellation or an inactive event); RSVP rows cascade with guest deletion; seating/table labels and reminders are not implemented.

**PLANNED — additive requirement received 2026-10-25; reviewed 2026-10-26 (Africa/Dar_es_Salaam).** This is a design contract for the pending invitation phases, not implemented functionality. Phase 4 authorization remains IN PROGRESS; the customer event portal and public signup remain closed. See the [project plan](PROJECT_PLAN.md), [reference flow](REFERENCE_UX_FLOW.md), [API contract](API_CONTRACT.md) and [database conventions](DATABASE_CONVENTIONS.md).

## CURRENT — inspected source and reuse

| Source | What exists | Gap for this journey |
|---|---|---|
| [Event](../app/Models/Event.php) and [event migration](../database/migrations/2026_05_29_130000_add_events_support.php) | Event/couple names, venue, date-only event_date and lifecycle status; nullable workspace ownership is prepared separately | No event category, time/timezone, RSVP settings or invitation design relationship |
| [Guest](../app/Models/Guest.php) | One event, name, normalized phone, manual code, pass type, allowed/used entries and admission usage status | No RSVP response or table assignment; usage status must not be repurposed |
| [QrCode](../app/Models/QrCode.php) | One QR row per guest, unique token, active/revoked metadata | No invitation access capability; the existing token remains the admission authority |
| [QR renderer](../app/Services/QrCodeService.php), [guest PNG renderer](../app/Services/GuestQrImageService.php) | QR encoding and a fixed QR/manual-code image | No general invitation engine; PNG helper can write a missing manual code, so it cannot be called blindly from a read-only public GET |
| [Web routes](../routes/web.php) | Customer workspace home/setup and authenticated scanner ticket URLs | No public personal invitation, RSVP or guest ticket route |
| [Notification log](../app/Models/NotificationLog.php) and [migration](../database/migrations/2026_06_02_130000_create_notification_logs_table.php) | Nullable guest/event links, provider message ID index, pending/sent/failed/skipped/simulated records | No batch recipient identity, RSVP, signed delivery reconciliation or durable invitation queue |
| [Scanner controller](../app/Http/Controllers/ScannerController.php), [offline admission applier](../app/Services/OfflineScannerResults/OfflineScannerAdmissionApplier.php) | Web admission transactions; the offline applier writes historical Admission/Checkin rows while importer/conflict logic handles reconciliation | Shared AdmissionService is planned, not an existing service; the applier alone does not enforce capacity |

This inspection covers repository schema and source. The configured customer database was not connected to or modified. No guest_rsvps, invitation_access_tokens or invitation design tables are currently claimed as implemented.

## TARGET — one guest journey, three independent state models

Personal invitation → card and event details → **Utahudhuria? / Will you attend?** → **Ndiyo, nitakuja / Yes, I'll attend** or **Siwezi / Unable to attend** → saved confirmation → authorized **Tiketi Yako / Your Ticket** → existing scanner admission.

No Kadify account is required for a guest. The personal link grants access only to its exact event/guest; it grants no customer, scanner, workspace or directory access. The page uses the original Kadify invitation design and displays names, date/time, venue, optional approved contact/directions, the response controls and the guest's own ticket when allowed. A response is saved explicitly by POST; opening a link, a link-preview crawler or downloading an image never answers an RSVP or consumes an entry.

| Model | Proposed meanings | Must never be used as |
|---|---|---|
| Invitation processing/delivery | Queued, processing, accepted by provider, delivered where evidenced, failed, skipped; see [messaging](WHATSAPP_INTEGRATION.md) | Evidence that a guest intends to attend |
| RSVP | pending, attending, not_attending | Guest admission usage status or provider delivery status |
| Admission | Existing active/partially_used/fully_used/cancelled pass state and allowed_entries/used_entries | RSVP response or a count of messages |

`not_invited` is derived when no issued-invitation RSVP record exists. `invited` is a reporting grouping, not an additional mutable response state. An explicit authorized issue operation creates the personal invitation and its pending RSVP record together, with `invited_at`. Merely previewing a card or selecting recipients does not issue an invitation. The **Invited** metric means **invitations issued**, not received: an issued invitation remains pending even if WhatsApp later fails. Creating a replacement access token does not reset invited_at or an existing response. Issuance for sending occurs after recipient review, and manual sharing has its own explicit issue action. Neither operation proves consent or delivery.

## PLANNED — response rules and idempotency

- Each event/guest has one current response: pending → attending or not_attending. Repeating the same response is a no-op for response timestamps and counters. Response changes between attending and not_attending are allowed only when the event's explicit change policy and closing time permit them. The proposed initial policy allows changes before the configured deadline; its default and any absence-of-deadline rule must be approved in the implementing task. There is no guest-controlled reset to pending.
- Persist invited_at, first responded_at, last response_changed_at, updated_at, response source and a monotonically increasing revision. Use UTC for instants. Preserve the first response timestamp on later changes; do not rewrite it for a retry.
- The form submits only the allowlisted response, expected revision and a server-issued submission key. Guest/event IDs, pass counts, table assignment, arbitrary source/channel and QR values are never accepted as authorization or writable RSVP fields.
- Revalidate token, event lifecycle, exact parents and RSVP rules within the write transaction using a reviewed lock order. Compare expected revision; an incompatible stale request returns 409 with the current response and no mutation. A cancelled/unavailable event cannot accept a new response. Rules for archived/read-only event history must be made explicit before activation.
- Persist an idempotency receipt and minimal audit for each accepted submission in the same transaction as the current response. An exact replay returns the receipt/current state without changing it; the same key with a different payload is 409. A replay of an earlier YES after a later NO must never restore YES. A new key that repeats the current response can record a no-change receipt without changing response_changed_at or revision.
- Token expiry/revocation and current access checks still apply to retries; a receipt is not authority. Double taps, refresh, two tabs, concurrent opposite responses, transaction rollback and lost success responses require tests. Do not maintain a separate incremented confirmation total.

## PLANNED — access capability and privacy

Generate each invitation access secret from at least 32 cryptographically random bytes and encode it canonically for URLs. Store only its SHA-256 hash for lookup, with an indexed unique constraint, exact event/guest lineage, issue/expiry/revocation timestamps and allowed purpose. Do not derive it from an ID, phone, manual code or QR token. Public URLs contain no sequential guest ID. A QR admission token is never reused as an RSVP access secret.

The initial `/invite/{token}` URL is a narrowly scoped bearer capability. Anyone possessing that link can view/respond as that invitation holder; do not describe it as verified personal identity. Support authorized link rotation/revocation, with a clear warning when old links stop working. Rotation never changes the existing QR/pass. Raw access secrets are returned only at issuance; later copies require a deliberate replacement/issuance flow or a separately reviewed protected recovery design, not plaintext lookup storage. Queue dispatch can use encrypted, time-bounded secret material that is erased under the agreed retention policy; redacted notification logs must not persist raw token-bearing URLs or messages.

Apply HTTPS, private/no-store caching, Referrer-Policy: no-referrer and noindex headers to page, response and ticket/media paths. Do not load third-party analytics, untrusted fonts or preview embeds on secret-bearing pages. Redact token path segments from web-server/proxy/application logs, error reports and monitoring; test the actual hosting configuration. Do not leak the secret into directions/contact URLs. Protect browser POSTs with CSRF and same-origin controls as well as the capability. Rate-limit reads by IP and writes by IP plus hashed capability; bound attempts and return Retry-After with 429. Numeric limits and distributed backing storage require an implementation contract and load test before release.

Malformed, missing, expired, revoked and mismatched capabilities return the same generic 404 without event/guest details. Recheck lineage and current policy for every page, media download, response and ticket request. The public projection excludes phone numbers, other guests, internal IDs, membership information and admission history. Private media must be authorized directly or use an expiring narrowly scoped reference; a public storage path bypass is unacceptable. GETs may establish browser session/CSRF state but must not create invitations, RSVP records, QR/manual codes or admissions.

## PLANNED — admission and ticket policy

| Event ticket-release policy | Guest display | Admission compatibility |
|---|---|---|
| existing_pass (backward-compatible default) | Show the existing authorized pass independently of RSVP; after an affirmative reply bring it into view immediately | No change to QR eligibility, capacity or old scanner behavior |
| after_positive_rsvp (future explicit opt-in) | Release the digital ticket view only while response is attending; decline/pending shows the response panel and guidance | Display release alone cannot invalidate an already downloaded QR. Any change to scanner admission eligibility needs a separate approved migration/product contract |

The second policy must not be enabled silently for legacy events. Releasing a ticket after YES does not issue a second pass; declining never deletes a guest, rotates a QR, zeroes usage or changes historical admissions. Expiring/revoking an invitation link removes access through that link, not the underlying admission permission. Admission revocation remains a separate existing authorized operation. Cancellation, inactive/revoked QR and fully used passes require honest display states; do not present a nonusable pass as guaranteeing entry.

Ticket fields come from the same freshly authorized guest/event/QR chain: guest name, event details, pass type, allowed entries, existing manual code, optional table label and the existing QR payload. Current pass rules are Single = 1, Double = 2 and Special/Family = configured 3–100 entries. Show usage/remaining separately when shown; an RSVP is a response for that invitation, not a request to change capacity. Table assignment is optional and must never be invented.

Reuse the current QR token and canonical scanner payload conventions through the planned shared pass boundary. `QrCodeService` encodes a supplied payload; it is not itself an authorization or token-issuance service. Preserve quiet zones, contrast and scanable size. If an authoritative QR/manual code is missing, return a safe unavailable state and direct the owner to an explicit authorized repair/generation action. Public GETs must not use helper side effects to repair it. Existing Scanner Website and Android contracts continue to enforce event match, allowed entries, offline rules and over-admission prevention through the same authoritative backend; an invitation URL is not a scan credential.

## PLANNED — minimal additive persistence design

These are proposed logical structures for P9 event details, P16-UX-01 engine/data contracts, P19 guest access/RSVP and P24 delivery review, not migration files or a frozen DDL contract. Preserve existing IDs, guest rows, QR values, manual codes, counters and history. All new tables use a numeric id primary key; external capabilities remain separate random values. Derive workspace through the authoritative event unless a deliberate snapshot/index requirement justifies storing it; every duplicated parent must be validated consistently.

| Structure | Proposed fields and relationships | Integrity/query requirements |
|---|---|---|
| events additions | event_type; nullable starts_at UTC plus event_timezone IANA identifier; edit revision; later RSVP closing/change settings and ticket_release_policy default existing_pass | Preserve event_date and old null times; never infer midnight/timezone for legacy data. Minimal Names binds to event_name; bride/groom and extended fields remain optional Quick Edit data, not a second event copy. Define synchronized local event_date/starts_at writes for newly timed events and conflict handling on edits |
| guests addition | nullable table_label, or a later normalized seating relation if actually required | Existing guest remains the source of identity/pass capacity; table is optional display data, not authority |
| guest_rsvps | event_id, guest_id, response, invited_at, responded_at, response_changed_at, response_source, revision, created_at/updated_at | Unique (event_id, guest_id); required parent links; index (event_id, response). Enforce the guest's event equality, not merely two independent FKs |
| guest_rsvp_submissions | rsvp_id, request_key_hash, request_fingerprint, previous/result response and revision, source, accepted_at, no_change flag | Required RSVP FK; unique (rsvp_id, request_key_hash); time index for audit/retention. No raw access token. Preserve receipts for the supported replay lifetime and audit retention |
| invitation_access_tokens | event_id, guest_id, token_hash, issued_at, expires_at, revoked_at, purpose; optional issued_by actor | Unique token_hash; index (event_id, guest_id) and expiry; required exact parents and bounded validity. Token rotation is audited, old capability revoked, response unchanged |
| invitation_templates / invitation_designs / versions | Existing planned original engine library and event-owned saved design/version | Reuse [engine contract](INVITATION_TEMPLATE_ENGINE.md); validate event/design/asset/guest association. Do not create an RSVP-specific template engine or copy guest identity into a second guest table |
| invitation_batches | event_id, initiating actor, request_key_hash/fingerprint, design revision, template/language, created_at | Unique (event_id, initiating actor, request_key_hash); exact authorized replay; immutable confirmed content/selection. Retain references needed to explain each send |
| invitation_batch_recipients | batch_id, guest_id, processing state, send outcome, delivery state, consent reference, claim/lease and outcome timestamps | Unique (batch_id, guest_id); index (batch_id, processing_state); verify batch/event/guest parents. One logical recipient despite retries; delivered/read states require matched authenticated evidence, never inferred from provider acceptance |
| notification_logs extension | batch_recipient_id, attempt number/key, accepted/provider status timestamps, safe error/evidence fields as needed | Reuse current attempts; unique (batch_recipient_id, attempt number), scoped provider-account/message identity after duplicate audit. Existing nullable legacy logs remain compatible |

Prefer required restrictive parent links for new live invitation/response records until a reviewed retention/deletion workflow exists. Do not introduce cascades that erase RSVP/audit history or change current guest deletion behavior accidentally; choose the final FK actions after legacy deletion/retention review. Composite event/guest constraints require a supporting candidate key and actual-schema validation; otherwise enforce exact lineage under the same transaction and test competing guest movement. Merely adding event_id and guest_id FKs does not prove their relationship. Published designs and queued payloads need a policy for later edits; the proposed batch pins a design revision while the public page shows the event's current approved version with clear saved-event consistency.

A separate invitation_deliveries table is not presumed necessary: use batch recipient state plus existing notification attempts. If a webhook inbox/outbox is needed for durable deduplication, define its minimal keyed evidence in Phase 24 rather than duplicating guests/passes. Token/RSVP/batch schema rollout requires additive migrations, restored backup evidence, populated-legacy conservation, actual supported-engine uniqueness/concurrency and guarded reversal/forward-repair checks. No backfill should infer RSVP, sent/delivered, time-of-day or consent from old rows.

## PLANNED — server-derived summaries

| Metric | Meaning and unit |
|---|---|
| Invited | Distinct current event/guest RSVP records with invited_at: invitations issued, not delivered |
| Attending / Wamethibitisha | Number of those invitations with response attending; one Double pass contributes one response |
| Not Attending | Number with response not_attending |
| Awaiting Response | Number with response pending; attending + not_attending + pending = issued invitations in the same scope |
| Optional expected capacity | Sum of allowed_entries for attending passes, explicitly labelled maximum invited capacity, not actual people promised or admitted |
| Messages Sent / Failed | Distinct recipient outcomes within the named batch or defined latest-per-guest event scope; retries do not inflate counts. Distinguish send failure from later delivery failure |
| Admitted | Existing authoritative event pass used_entries aggregate, with existing admission history/reconciliation semantics; not RSVP totals |
| Remaining | Sum of remainingEntries for the explicitly defined eligible pass scope; cancelled/revoked passes must not be presented as available entry capacity |
| Fully Used / Partial Passes | Counts of noncancelled passes with used_entries = allowed_entries, or 0 < used_entries < allowed_entries; display separately from people admitted |

Do not join attempts/tokens directly into an RSVP sum and multiply rows. Use independent scoped aggregates, consistent query snapshots or an as-of timestamp, pagination for lists and bounded authorized polling with backoff. Define event-level latest-recipient messaging aggregation before implementing it. Admissions from disconnected devices remain provisional/unavailable until the existing reconciliation process commits them; do not claim a global real-time attendance guarantee. Historical deleted-guest/report behavior is an existing separate audit issue, not permission to silently recalculate old history.

## PLANNED — service and verification boundaries

EventService persists validated category/minimal details against the real event; GuestService/GuestImportService reuse current guest and CSV/Excel behavior; InvitationService manages saved design, access issuance and recipients; InvitationRenderService handles original preview/export; RsvpService handles response rules and receipts; EventReportService aggregates separate states. Names are proposed responsibilities and extraction should follow the existing [reuse map](ARCHITECTURE.md). NotificationService remains the Meta provider boundary. QrCodeService and current pass/scan logic remain authoritative, with AdmissionService shared extraction under Phase 5/13.

P19-UX-01 secures the guest page before P19-UX-02 responses; P19-UX-03 integrates existing tickets; P19-UX-04 adds scoped summaries. P24 adds actual delivery/progress and live portal integration. Required tests include foreign workspace/event/guest/design/QR substitution, hash-only token storage, invalid/expired/revoked links, read-only GETs, CSRF/rate limits, replay/concurrent response changes, rollback/audit consistency, both ticket-release policies, unchanged legacy tokens/capacity/history, one logical recipient across retries, revoked access during queued work and scanner capacity races. Complete the customer → guest → scanner journey in SW/EN at 320, 360, 375, 390, 430, 768, 1024, 1280 and 1440px, including back navigation, missing QR, decline, network failure and keyboard access. See [test strategy](TEST_STRATEGY.md).

Before implementation, settle response-change/deadline defaults, token expiry/retention, exact rate limits, seating needs, deletion/retention actions, public design-update behavior and any future admission eligibility policy. These do not block documenting the journey; they do block silently inventing production rules. This update adds no schema, route, actual message, public guest access or deployment.
