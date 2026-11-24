 # Kadify project plan

Updated 2026-11-24 (Africa/Dar_es_Salaam). Read with [architecture](ARCHITECTURE.md), [API](API_CONTRACT.md) and [database conventions](DATABASE_CONVENTIONS.md).

## Invitation Sharing Center — 2026-11-24

**Status: COMPLETE (SOURCE LEVEL), migration applied to the local database after a backup.** No messaging API, gateway or automation: the server prepares text and links; the customer opens WhatsApp / Messages / the share sheet with a tap and sends.

- **Migration `2026_11_24_000000_create_invitation_sharing_tables` (additive):** `invitation_links` (one per guest; SHA-256 of a 32-char random key for lookup, key encrypted with APP_KEY for re-copying, bound to the QR credential fingerprint), `invitation_share_templates`, `invitation_share_sessions` (customer, event, channel, template, message, recipient guest ids, position, status, last activity), `invitation_shares` (guest, event, session, channel, status initiated/confirmed/skipped, attempts, timestamps). No guest data is copied.
- **Services:** `Sharing\ShareMessage` (fixed placeholders, plain-text cleaning, validation, `wa.me` / `sms:?&body=` links with rawurlencode, phone normalisation, GSM-7/UCS-2 SMS segment estimate), `Sharing\InvitationLinks` (issue/resolve; a link works only while the guest is not cancelled, the event is active, the QR is active and the stored card matches the current credential; replacing the QR kills the old link), `Sharing\ShareRecords` (honest per-channel state; confirmed outranks initiated outranks skipped; idempotent confirm; "undo sent").
- **Routes:** 13 under `customer.events.share*` (hub, wizard, selection hand-off, sessions store/show/progress, one guest, record, history, templates CRUD) behind auth + role:customer + WorkspaceAccess (`event.view`; writes `event.update` + active event); sessions are also scoped to the customer who started them. Public `invitations.public` and `.card` (`/i/{key}`, throttle 60/min, noindex, no-store, no-referrer, DENY framing). Route total 169.
- **UI:** Share tab replaces Invites in the bottom bar; sidebar adds Share invitations and Sharing history; ⋯ sheet adds history and templates. Guests list: Sharing filter, sharing pill, Share action per guest and a bulk Share button. Guided run: send button and "Did you send it?" first on phones, card thumbnail beside the editable message, copy message/link, share card image (Web Share API with files, from the tap, image pre-fetched), download, previous/skip/next, pause/continue, completion summary listing guests not yet confirmed.
- **Evidence:** `ShareMessageTest` (6) and `InvitationSharingTest` (8): link encoding, personal messages, public-link isolation and revocation, honest/idempotent recording, guest/QR/admission/notification tables unchanged, no HTTP sent, session progress/pause/resume/complete, other customer / other manager / other event 404, selection hand-off, templates validation and scope, read-only events. Final full suite: **3,045 tests / 74,722 assertions, zero failures**. Headless Edge on the sandbox copy at 390, 360 and 1440px: wizard → run → WhatsApp/SMS links (intercepted, nothing left the machine) → confirm → skip → share image (mocked share sheet) → pause → resume → one-guest page → history → templates → public page; no overflow, no console errors, no external requests.
- **Limits:** not tested on a physical phone. `sms:` body prefill and Web Share with files vary by phone and browser (fallbacks: copy message, share text, download). WhatsApp opening does not mean sending; confirmations are the customer's word. Links use the request host (APP_URL in `.env` is stale). Links break if APP_KEY changes only for re-copying (a new link is issued). Guests need a generated card for the current QR before they can be shared.

## Customer experience — 2026-11-24

**Status: COMPLETE (SOURCE LEVEL) for the scope below.** No migration; no schema change.

- **Guest entry without spreadsheets:**
  - `GuestTextParser` is deterministic, with no AI. Names mode works line by line, splits on commas and semicolons, strips list markers, picks out phones, and offers an optional one-word-per-guest split. Structured mode chooses the separator (tab, `|`, `;` or `,`) per line and classifies cells by content (phone, count, pass word, name), so column order does not matter. It skips a header line.
  - Documented defaults: a blank pass becomes Single; VIP, Regular and similar labels take their pass from the number of people; a number that contradicts Single or Double wins.
  - New routes:
    - `customer.events.guests.review` (POST, parse and review; nothing is saved)
    - `customer.events.guests.bulk` (POST; re-validates every row; nothing is saved while any row is invalid; duplicates in the event or the list are skipped and re-checked inside the transaction; saved through `CustomerGuests::create()`, so credentials and manual codes are identical to import)
    - `customer.events.guests.import.sample_csv`
  - Rows travel as one `rows_json` field (PHP `max_input_vars` is 1000), with `rows[]` as a no-JS fallback. The limit is 1,000 rows.
  - Contact Picker API on supporting browsers only, after a tap; otherwise a fallback message.
- **Optional phone:** stored as `''` (the column stays NOT NULL). The `Guest` mutator accepts an empty string and still normalises or rejects anything else. Admin forms and imports still require a phone through their own validation. Duplicate checks ignore empty phones. WhatsApp sending returns `no_phone` and sends nothing. Customer guest names must contain a letter.
- **Import:**
  - The customer `.xlsx` reader uses header aliases and needs only a name column (the admin reader is unchanged).
  - CSV likewise.
  - A blank pass is decided by the count.
  - The preview can send every row to the editable review.
- **Navigation shell (redesigned 2026-11-24 after a UX audit: the hamburger drawer had 12 mixed links and phones showed two stacked headers):** `partials/customer_shell` is on every customer page. Phones use an app bar (back to parent, context, ⋯), bottom tabs (3 outside an event; 5 inside with a raised centre + Add) and a ⋯ bottom sheet with a focus trap, Esc and swipe-down. Task routes hide the tabs. The pages' own header rows are hidden at all sizes, and the language switch moved to the sheet (phones) or the bar (desktop). The desktop sidebar mirrors the tab names. Earlier description: Phones get a sticky bar with breadcrumbs and a focus-trapped drawer; screens 1024px and wider get a fixed sidebar. It has global and event sections and a language switch. The old bottom tab bars, with their two dead items, are hidden. New `customer.help` page.
- **Design system and layout:** `customer_shell.css` defines the tokens and components. On desktop the 28rem pages widen with `--rp` pinned, so type keeps its size. Pre-existing bug fixed: the guest-list action bar sat outside the page container, so its button scaled with the viewport on wide screens. The editor uses two columns from 1200px.
- **Overview on My Events:** every read goes through the authorized-events subquery, so the membership check is in every query (`CustomerHomeBoundaryTest` enforces this).
- **Templates:**
  - `InvitationTemplateCatalog::FEATURED` (10 families) adds a "Featured" chip.
  - The guest name is larger (36 / 46 script, was 30 / 38) and the box is wider (640, was 600), with no geometry change elsewhere, so the content area and the QR tile and quiet zone are unchanged.
  - `VERSION` went from 1 to 2, so earlier cards show as outdated.
- **Advanced guest list:**
  - `GuestDirectory` (event-scoped):
    - SQL search: partial name, phone digits (a local `07…` form matches the stored `+255…`), 4-character invitation code, pass word, status word.
    - SQL filters: pass type (the configured Single/Double/Special; no VIP/VVIP types exist), admission (not checked in / partial / full / cancelled), phone present or missing, date added, people per pass, entries remaining.
    - SQL sorts: 8 sorts.
    - Card state (SHA-256 of the QR token) and the latest WhatsApp status are computed for the matching rows only. Invitation filters, the card-date sort and paging (50 per page, up to 5,000 matches) are then applied on the server.
    - The sending filters are shown only when WhatsApp is configured.
  - UI: the desktop table becomes cards below 1360px; debounced live search swaps only the results region; a filter bottom sheet with active chips and a count; select the page or all matching guests.
  - Bulk delete (`customer.events.guests.bulk_destroy`, JSON, `confirm` required, up to 500) goes through `CustomerGuests::delete()`, so admitted guests are kept and the offline lock applies.
  - ZIP download also accepts one `guest_ids` list, because `guests[]` hit PHP `max_input_vars`.
  - Old `?filter=` links still work.
  - `CustomerGuestListTest` (7). The full suite after this: **3,031 tests / 74,319 assertions, zero failures**.
- **Evidence:**
  - New tests `GuestTextParserTest` (10) and `CustomerGuestEntryTest` (9). Route inventories and link allowlists were updated for the new routes and shell links.
  - Full suite **3,024 tests / 74,229 assertions, zero failures**, run with the default WhatsApp provider; HTTP is faked.
  - Headless Edge on a cloned MySQL database: 11 pages × 6 viewports (360, 390, 430, 768, 1024, 1440), with no horizontal overflow and no console errors.
  - End to end at 390px: 100 pasted names, reviewed and saved; cards generated through the existing endpoint; PNG card 200 `image/png`; ZIP 200 `application/zip`.
- **Limits:**
  - Not verified on a physical phone; Contact Picker not exercised (needs Chrome on Android over HTTPS).
  - The 100 templates were not individually redesigned; only the curated collection and the guest block changed.
  - Offline packages for customer events (still disabled) would need `''` phones accepted by `OfflineScannerPackageValidator`.
  - Bulk guest deletion was not added.

## Feature batch — 2026-11-23 (P4-048 online assignment, offline signing tooling, admin customer management, customer self-service)

**Status: COMPLETE (SOURCE LEVEL) for the scope below; P4-048 offline support for customer events NOT implemented; production signing key NOT configured.**

- **P4-048 (online):** administrators can assign staff scanners to active customer-created Events (Admin → Scanner Users, labelled "Customer: workspace"). The scanner endpoints were already Event-scoped, so admission limits, wrong-event refusal, revocation and duplicate prevention apply unchanged. `LegacyStaffAccountBoundaryTest` cases that encoded "owned events are not assignable" were updated to the new rule; every other protected identity kind remains refused. **Not done:** offline sessions for customer Events. Admin event selection, the admin layout and every offline service are admin-Event scoped. The boundary still refuses (404, tested). Enabling it means opening the P4-047 admin read family to customer Events.
- **Offline Ed25519 signing:** `PackageSignatureKeys`, plus the manual commands `offline-scanner:signing-key-generate` and `offline-scanner:signing-key-check`. See [OFFLINE_SIGNING_KEYS.md](OFFLINE_SIGNING_KEYS.md) for key encoding, procedure, Android trust-store requirements and rotation. No key was generated for any environment. The Android source is not in the repository.
- **Admin → Customers:** list, search, filters, pagination, profile (workspaces, events, guests, audit history), edit, suspend/reactivate, and safe delete. Delete is refused while the customer has events; it removes only empty sole-member workspaces. Actions are transactional and recorded in the new `admin_audit_logs` table (migration `2026_11_23_000000`, additive, applied to the local DB after a backup).
- **Customer self-service:**
  - My profile, with the current password required to change email or password.
  - Edit an event (`event.update`).
  - Delete an event (new owner-only `event.delete` in `WorkspaceAccess`). The typed name is required. Refused after any admission, an offline session, or a scanner assignment.
  - Edit and delete guests. The allowance can't go below entries already used. Printed changes retire the guest's card. Admitted guests can't be deleted. The offline lock is respected.
- **Evidence:**
  - New test classes `ScannerCustomerEventAssignmentTest`, `OfflineSigningKeyTest`, `AdminCustomerManagementTest`, `CustomerSelfServiceTest` (39 tests / 326 assertions).
  - Full suite **3,005 tests / 73,939 assertions** with 2 link-allowlist failures. These were updated for the new real Profile/Edit links. The final affected-area gate (ReferenceUi, Customer, Scanner, Admission, Offline, Invitation, Workspace, staff, layout, headers) on the final source passed **1,664 tests / 55,837 assertions**, zero failures.
  - MySQL HTTP end-to-end on a cloned database: 31/31 checks. These include 8 concurrent admissions across two server processes on a 2-entry customer pass (exactly 2 admitted) and real FK cascades on event/guest/customer deletion. Earlier scanner (27), offline (17) and crawl (4 areas) scripts also passed.
- **Limits:** no real Android device, production key, hosting `php.ini`, browser/mobile visual QA or WhatsApp delivery was verified.

## Guest allowance maintenance — 2026-11-12

**Status: COMPLETE (logic-only maintenance).**

User-requested logic-only update: Special / Family passes with a custom allowance now support 3–100 guests. Single remains 1 and Double remains 2. The model, create/edit/import validation, issuance guard, QR/manual admission, offline manual-count correction, inventory checks and browser constraints use the new maximum. Existing passes keep their configured allowances until edited. Layout, styling and CSS classes are unchanged; only numerical constraints and range guidance changed.

Verified so far: focused runs 255 tests / 2,572 assertions and 44 tests / 1,257 assertions pass; ten actual guest-form JavaScript handler cases pass; seven PHP syntax checks and the production Vite build pass. All four CSS sources and four compiled CSS assets are byte-identical to the prior build. Pint findings in two production files match their untouched backups; the other five changed PHP files pass. Full regression completed: **2,715 tests / 47,442 assertions**, **2,713 passed**, two failures, zero errors or skips. The two failures are existing route-count assertions in CustomerHomeBoundaryTest and CustomerWorkspaceCreationTest (expected 100 routes, actual 104); both reproduce using the untouched original ten-guest PHP logic through a temporary autoloader, whose source path and old cap were independently verified. The app/test route definitions were not changed by this maintenance task.

This maintenance update does not complete P4-048 or any later roadmap/UI phase. No schema migration, account/ownership change, scheduled continuation or deployment was performed.

## Execution status

- Current source task: Phase4 authorization preparation; Phase3 configured database/legacy rollout gates remain open. Phase2 repository gate COMPLETE.
- Current task: P4-048 - IN PROGRESS; Step 3 COMPLETE (SOURCE LEVEL), 2026-11-11: `POST /scanner/admit` now checks current Event lifecycle and scanner authority inside its admission transaction. [Step 3 scope and focused evidence](#task-p4-048---step-3-complete-source-level-2026-11-11). Steps 1/2 retain their accepted scopes. P4-047 remains COMPLETE (SOURCE LEVEL); family 3 is accepted, scanner family 4 remains OPEN and Phase4 remains IN PROGRESS. No scheduled continuation.
- Latest completed and implemented source task: P4-047 - whole legacy read/export/private-media family. P4-045 issuance, P4-043 deletion and earlier metadata/offline/admission checks retain their accepted scopes; caller/commit, file recovery and customer rollout prerequisites remain open.
- Status: P2-001 through P2-005 COMPLETE in source and isolated tests. P3-001 inventory, P3-002 dormant schema, P3-003 explicit proposal preflight, P3-004 private identity/ledger and P3-005 dormant backfill service are COMPLETE in source/tests; P3-006 synthetic MySQL rehearsal, P3-007 read-only exact progress inspection and P3-008 dormant authority context are COMPLETE; P3-009 backfill concurrency, P3-010 admission conservation and P3-011 authority visibility rehearsals are COMPLETE; Phase 3 remains IN PROGRESS and actual database/ownership verification remains open.
- Latest focused source gate: P4-048 Step 3 admission/manual/offline regressions **63 tests / 454 assertions**, zero errors/failures/skips; new admission boundary **28 / 144**. Step 2's **97 / 794** evidence is retained, not rerun. The latest full run is the 2026-11-12 guest-allowance maintenance check: **2,715 tests / 47,442 assertions**, with two pre-existing route-count failures reproduced on the original guest logic. The latest fully green full-suite checkpoint remains P4-047's **2,579 tests / 46,472 assertions** in 96 classes (2026-11-11). At that full gate all 2,567 slice-4 cases retained exact assertion counts; 12 F1/F2 cases added 143 assertions, with focused boundary 291 / 1,801 and P4-045's documented +1 query-count delta retained. Earlier 100-route / 32-table inventories were not rerun. [Plain-language status](PROJECT_STATUS.md).
- Historical audit evidence (2026-10-11): source inventory, Laravel/PHP versions, 88 routes and baseline suite (197 tests: 196 passed, 1 failed).
- Completed: Phase0 documentation and Phase1 repository audit;43Markdown references,372local links validated;230non-doc files unchanged during audit.
- P4-048 Step 1 was authorized by the 2026-11-11 bounded scanner request; subsequent continuation requests resumed Step 2. The explicit Step 3 request authorizes the current `POST /scanner/admit` lifecycle/authority correction and focused verification. Next small action, not started: inspect the active-Event write boundary on `POST /scanner/manual-admit`. Private QR cutover remains a gated operator activity ([deployment item 10](DEPLOYMENT_CPANEL.md)). Other scanner current-authority requirements, Phase5, customer Event routes, approved-reference UI and actual rollout retain their prerequisites.
- External verification gaps: configured connection refresh (2026-10-21) failed safely with 2002 before SELECT; Android source absent; actual browser/device checks and cPanel/mail/cron remain unverified.
- Authorization: original request limited first execution to phases 0/1; subsequent user instruction authorizes continuing without approval pauses. Report each phase, continue in sequence, preserve one-task-at-a-time discipline.
- Continued authorization (2026-10-26): the user explicitly requests continuing without stopping. Complete each task's checks, report progress and open the next bounded task without routine confirmation; retain real-data/deployment and unavailable-evidence gates.
- Continuation status (2026-11-11): bounded source work resumed after the previous Step 1 stop; the current request is Step 3 only. `Harusi-Codex-Continue-4h` was disabled on 2026-11-09 and last read-only confirmed Disabled during Step 2. Step 3 performs no scheduling action. [Operation and stop instructions](LOCAL_CONTINUATION.md).
- Additive customer-experience update (2026-10-25): eight reference screens integrated into TARGET/PLANNED contracts and pending phase dependencies. P4-020 was IN PROGRESS during that documentation integration; it did not implement invitation features, apply migrations or activate routes. Subsequent P4-020 source verification is recorded below. [Integration scope and acceptance](phases/CUSTOMER_EXPERIENCE_INTEGRATION.md).
- Product-wide UI clarification (2026-10-26): the RSVP reference's simplicity and original Kadify design language now govern every interface. Optimize each task for easy use; apply shared principles and components through existing phases. P6-UX-02 and P27-UX-02 add screen-inventory/rollout and usability gates; all new work stays NOT STARTED and P4-020 is unchanged. [Standard](DESIGN_SYSTEM.md).

## Progress log

| Date | Task | Verified progress / remaining work |
|---|---|---|
| 2026-11-12 | Guest allowance maintenance | COMPLETE: Special/Family custom allowances 3–100 across model, CRUD/import, issuance, QR/manual admission, offline corrections and inventory/browser constraints. Single/Double unchanged. Focused 255/2,572 and 44/1,257 pass; JavaScript10 cases and Vite build pass; all CSS sources/builds unchanged. Full 2,715/47,442: 2,713 pass, two pre-existing route-count failures reproduced on original logic. No schema, layout/style or roadmap-phase completion change. |
| 2026-11-11 | P4-048 / Step 3 | COMPLETE (SOURCE LEVEL), P4-048 remains IN PROGRESS. `/scanner/admit` now requires a current primary locked active Event and current locked scanner before offline/QR/Guest checks and all scan/admission writes. Missing explicit Event pointers refuse instead of falling back; genuine null-assignment selected/default fallback is retained. Original 23-case boundary: 18 failures, 5 controls passed. Final expanded boundary 28 / 144; focused admission/manual/offline regressions 63 / 454; PHP lint, Pint and independent AI review APPROVE. Step 1/2 and manual production code unchanged; full suite and actual engine concurrency not rerun. Family 4 and rollout remain open. |
| 2026-11-11 | P4-048 / Step 2 | IN PROGRESS. `POST /scanner/verify` now checks the resolved Event's current primary-database status before input validation, QR lookup or scan writes; inactive Events return 403. Original source: 9 intended failures, 16 controls passed. Corrected verification: 25 tests / 159 assertions; focused scanner/QR regressions: 97 / 794; PHP lint and Pint pass. Independent bounded AI review: APPROVE. Nested payloads, fallback, business responses, counters/history and offline 423 behavior retained. Full suite not rerun; family 4 and rollout gates remain open; scheduled continuation stays Disabled. |
| 2026-11-11 | P4-048 / Step 1 | IN PROGRESS. `POST /scanner/validate` now checks the resolved Event's current primary-database status before QR lookup or scan writes; archived/cancelled Events return 403. Original source: 5 status cases failed, 15 controls passed. Corrected validation: 20 tests / 84 assertions; focused scanner regressions: 57 / 414; PHP lint and Pint pass. Existing unassigned fallback, business responses, counters/history and offline blocking retained. Other family-4 requirements and rollout gates stay open; continuation remains Disabled; stop after this step. |
| 2026-11-11 | P4-047 | COMPLETE (SOURCE LEVEL). F1 and F2 verified together; focused 291 / 1,801 and one full run 2,579 / 46,472, zero errors/failures/skips. All 2,567 prior cases exact; 12 additions. One independent AI review: APPROVED WITH NONBLOCKING FOLLOW-UPS. N1-N5 retained in the Phase4 report; no source changes. Scanner family 4, Phase4 and external rollout gates remain open; continuation stays disabled. |
| 2026-11-09 | P4-047 | IN PROGRESS slice 4 installed: dashboard, Guest list/detail/edit, QR list, check-ins, notification logs, reports, import GET and preview, and all six exports read in one attempt that includes the layout. Original source: 60 of 82 observation cases fail for the designed reasons, 22 controls pass. Candidate: read bundle boundary 135 / 641, 45 mutations caught through a TEMP overlay, full 2,567 tests / 46,329 assertions with all 2,288 retained cases exact except the same documented +1. MySQL 8.4.11 and MariaDB 10.4.32 pass 45 selected cases each, and the first run found and led to fixes for locks held across Events. Only the final independent review remains. |
| 2026-11-09 | P4-047 | IN PROGRESS slice 3 review addressed (APPROVE WITH FIXES, no blocker): shared instead of exclusive locks on the layout reads, immutable layout values instead of models, a composer refusal plus a wider source scan for unregistered layout views, corrected docs. Layout boundary 56 / 244, 8 mutations caught, full 2,431 tests / 45,686 assertions with all 2,288 retained cases exact except the same documented +1 query-count assertion. A killed mutation run had left one mutation in `LegacyAdminReadProjection.php`; it was restored and hash-verified, and mutation runs now use a TEMP autoloader overlay that writes nothing to the repository. Per-page read bundles, duplicate preview and MySQL observations remain. |
| 2026-11-08 | P4-047 | IN PROGRESS slice 3 installed: `LegacyAdminReadProjection::layout()` prepares the shared admin layout before rendering in one attempt (locked Event → actor → Session; unlocked package/creator/catalogue labels); a view creator covers all 22 admin-layout views and the composer issues no SQL; `admin.private` makes the 11 family-3 HTML routes private no-store/nosniff. Original source: 31 TEMP gaps fail, 3 controls pass. Candidate: layout boundary 54 / 208, 7 mutations caught, full 2,429 / 45,650 with all 2,288 retained cases exact except one documented +1 query-count assertion. Independent review in progress; per-page read bundles, duplicate preview and MySQL observations remain. |
| 2026-11-08 | P4-047 | IN PROGRESS slice 2 installed: one private guest_qr storage policy (configuration/root/handle/exact-key checks) shared by issuance, deletion and new buffered delivery; shared-path veto before the compatible-image probe; locked complete-set PNG/ZIP delivery with private no-store/nosniff headers, ZIP32 bounds and SW/EN failure feedback; Apache `storage/qr-codes` denial; nine QR fixture classes adapted by recorded scripts. Slice 1 review (APPROVE WITH FIXES) addressed: no-store/nosniff CSV/XLSX, success-path import-result recheck, withheld-result guidance, 8 added cases. Original source: 11 media gaps fail, 4 controls pass. Slice 2 review APPROVE WITH FIXES: delivery-recheck/key/ZIP32/foreign-root tests, actual I/O-root introspection, device-name slug suffix, extended device/8.3 key rules, ZIP64-sentinel bounds, public-disk observation in the spy classes and doc corrections; the fixes were re-reviewed APPROVE and 14 temporary mutations each failed their targeted cases. Final: media boundary 61 / 424, read boundary 26 / 290, nine adapted classes 322 / 3,866, full 2,375 / 45,441 with all 2,288 retained cases exact. No real file copied; QR media, QR generation, Guest creation and deletion of Guests with a QR path fail closed until the gated cutover creates the private root. Read bundles and MySQL observations remain. |
| 2026-11-07 | P4-047 | IN PROGRESS slice 1 installed: selected-Event Guest/scanner history decoration, Event-bound check-in search, Guest+Event history counts/detail, exact selected-Event notification logs, actor/Event-bound import results, CSV/XLSX serialized before response, offline-banner creator/package lineage. Original source: 9/12 TEMP gap cases fail as predicted; final boundary class fails 15/18 with 3 controls passing. Candidate: boundary 18 tests / 203 assertions; full 2,306 tests / 44,930 assertions with all 92 prior classes exact. Private QR storage/media, locked read bundles/zero render-time SQL, MySQL observations and independent review remain. |
| 2026-11-05 | P4-047 | IN PROGRESS: captured 533-path baseline (432 non-doc/371 tested-source); preparing guarded TEMP original-source regression observations. No candidate installed, backend gate remains P4-045. |
| 2026-11-05 | P4-046 | COMPLETE design/source audit with independent read/media approval. Current projections, bound import results, buffered exports, private QR storage/delivery and gated cutover specified. All 432 non-doc/371 tested-source paths and reference/status evidence preserved; P4-045 retains 2,288/44,727 without rerun. Family 3 source remains OPEN; P4-047 implementation next. |
| 2026-11-04 | P4-045 | COMPLETE whole Guest creation/import/QR issuance/repair source boundary. One mutation attempt/current authority, three QR modes, pure rendering, guarded paths and honest import interruption. Boundary 122 tests / 1,403 assertions, compatibility 246 tests / 2,825 assertions, focused 465 tests / 7,081 assertions, full 2,288 tests / 44,727 assertions; all 91 prior classes exact. Family 1 source accepted; families 3/4 and actual rollout remain open. |
| 2026-11-03 | P4-044 | COMPLETE as read-only whole create/import/generation/repair design; independent review and source/reference/document checks. No guard installed or backend rerun; P4-043 retains 2,166/43,324 by 365 tested-source hashes, all 416 non-doc paths preserved. Family1 and the other two remaining families stay OPEN; proposed P4-045 implementation next. |
| 2026-11-03 | P4-043 | COMPLETE single/bulk Guest deletion source boundary: one attempt, complete current set/global path veto before adapter/effects, strict reported outcomes and sanitized SW/EN feedback. Boundary 64 tests / 766 assertions; compatibility 149 tests / 1,823 assertions; focused 310 tests / 5,442 assertions; full 2,166 tests / 43,324 assertions; all 90 prior classes exact. Three finite families and actual rollout remain open. |
| 2026-11-02 | P4-042 | COMPLETE design/TEMP characterization: 12 cases / 168 assertions, current SQL/byte/false/cancel/stale-bulk outcomes observed. Reviewed one-attempt/current-set/shared-path guard and localized failure contract; no runtime fix. P4-040 retains 2,102/42,558; proposed P4-043 implementation next. |
| 2026-11-02 | P4-041 | COMPLETE documentation-only finite exit audit: four remaining runtime families, later P5 contracts and source/actual-environment gates. Independent review passed; P4-040 retains 2,102/42,558 with no new backend run. Proposed next: whole Guest deletion design/characterization. Phase4 remains IN PROGRESS. |
| 2026-11-02 | P4-040 | COMPLETE current authority for four Guest/QR metadata actions within unchanged three attempts; five existing guard reads plus current unique primary locked QR. Boundary 36/424, compatibility 75/672, focused 1,069/21,819, full 2,102/42,558; all 89 old classes preserved. Original HTTP 31/240/18 failures/zero errors; payload/status/missing-QR conserved. Finite remaining-surface/Phase4 exit audit next; callback/caller/commit/MySQL and rollout limits remain. |
| 2026-11-02 | P4-039 | COMPLETE single-Guest update/cancel current-authority fence within existing three attempts; frozen IDs/payload, five guard reads and current-used allowance floor. Boundary38/727, Guest/offline16/132, focused974/20,855, full2,066/42,134; all88 prior classes preserved. Original HTTP30/241/22 failures/zero errors; callback/caller/commit/MySQL and broader Guest/QR/customer/UI limits remain. |
| 2026-10-30 | UI-REF-001 | COMPLETE documentation/measurement integration: all 39 approved PangaEvents originals inspected and hash-preserved; four specialist UI documents, measurement evidence and A-H phase tasks added. Approved UI completion 0/39; visual/functional QA NOT RUN. All 406 non-doc paths / 357 tested-source paths and 20 previous UX rows preserved; P4-038 retains 2,028/41,407. |
| 2026-10-30 | P4-038 | COMPLETE three independent one-attempt secure-delete stages; immutable scalar asset and actual persisted marker receipt, full persisted FILE_DELETED audit, no repeated unlink. Boundary91/3,683, byte36/846, deletion17/353, workflow11/189, focused927/20,063, full2,028/41,407; all87 prior classes retained. Named SQL partial outcomes preserved, callback rollback windows explicit; recovery/provenance/commit/caller limits remain. |
| 2026-10-29 | P4-037 | COMPLETE design only: immutable scalar asset context, actual persisted metadata receipt and newly persisted FILE_DELETED audit are planned across separate one-attempt current-authority write stages. No new outer transaction or byte repeat. P4-036 retains1,937/37,724 by356 source fingerprints;366 non-doc paths and100-route/32-table CLI preserved. |
| 2026-10-29 | P4-036 | COMPLETE bounded secure-delete byte authority: one-attempt primary Event/admin/Session/Artifact prefix, captured path/raw disk/fallback, exclusive current-context provider and shared retention-rechecking byte primitive. Boundary36/846, deletion compatibility17/353, workflow11/189, focused836/16,380, full1,937/37,724; all86 prior classes preserved. R33 only partly mitigated; later metadata/audit authority and recovery remain open. |
| 2026-10-29 | P4-035 | COMPLETE design audit: one-attempt private-byte authority fence planned, with a single byte primitive and trusted current-context provider; metadata/audit keep existing separate commits. No runtime deletion change. All354 source fingerprints/364 non-doc paths and100-route/32-table CLI preserved. |
| 2026-10-29 | P4-034 | COMPLETE bounded known root callback retry cleanup: immutable marked path/disk/PDO, fresh authority and any-path positive reference veto before file access; missing/false/throw outcomes and commit-class exclusions checked. Boundary17/783, workflow11/189, focused800/15,534, full1,901/36,878; all85 prior classes preserved. R32 partly mitigated; unknown commit/crash/caller and R33 remain open. |
| 2026-10-28 | P4-033 | COMPLETE design audit: two independent reviews define root callback retry cleanup with physical-state qualifications and a current positive-reference veto. No runtime repair; P4-031 source gate retained by353 fingerprints. Separate implementation/testing is next. |
| 2026-10-28 | P4-032 | COMPLETE TEMP probe: 3 cases / 269 assertions confirms unreferenced earlier-attempt files after known SQL rollback/retry; success2 leaves1 and exhausted3 leaves2, ordinary one-attempt cleanup leaves0. No runtime repair. P4-031 source gate retained by353 fingerprints;363 unrelated paths and100-route/32-table CLI preserved. |
| 2026-10-28 | P4-031 | COMPLETE: current Event/admin/Session prefix protects preview writes and its internal duplicate audit inside the existing storage transaction/cleanup. Boundary 13/363, workflow11/189, focused 783/14,751, full 1,884/36,095; all84 prior classes preserved. Child recertification and artifact/deletion recovery remain separate. |
| 2026-10-28 | P4-030 | COMPLETE: current Event/admin/Session checks protect each external preliminary audit independently of preview success. Boundary 16/282, workflow 11/189, focused 770/14,388, full 1,871/35,732; all 83 prior classes preserved. Preview storage authority and recovery remain separate. |
| 2026-10-11 | P0-001 / P1-001 | Documentation/audit gate passed: 43 files, 372 local links, source states and whitespace; 230 non-doc files unchanged. Baseline 196/197 tests passed; two diagnostic auth probes reproduced defects; temporary build passed. |
| 2026-10-12 | P2-001 | COMPLETE: full regression passed, 227 tests / 1,768 assertions. Seven task files changed; .env, existing builds and all other checked non-doc files match the audit baseline. |
| 2026-10-12 | P2-002 | COMPLETE: full suite passed 265 tests / 1,946 assertions; new-file Pint, syntax/whitespace and temporary frontend build passed. Canonical account phones, active sessions/roles and new offline-package eligibility verified. Actual DB rollout remains unverified. |
| 2026-10-12 | P2-003 | IN PROGRESS: recovery/session task plan being finalized; implementation and verification pending. |
| 2026-10-13 | P2-003 | Resumed after usage interruption. Current source still at the verified P2-002 gate; password recovery files are not yet written. Continuing the agreed recovery and session-revocation contract, with SMTP/hosting proof kept separate. |
| 2026-10-14 | P2-003 | COMPLETE: 40 focused tests / 228 assertions and full 305-test suite / 2,174 assertions passed. Registered-email recovery, password/remember/session revocation, failure sanitation, CSRF and throttles verified in isolation; Pint, syntax, 94-route registration and temporary Vite build passed. Real SMTP/hosting/MySQL concurrency remain unverified. |
| 2026-10-14 | P2-004 | IN PROGRESS: Sanctum4.3.3 compatibility and scoped Composer dry run verified (one added package, no dependency updates/removals). Exact token-only API, expiry, abilities, eligibility and revocation rules recorded before implementation. |
| 2026-10-16 | P2-004 | COMPLETE: 353 tests / 3,049 assertions passed, including 48 tests / 875 assertions for device tokens; HTTPS/expiry/abilities, browser isolation, active event checks and transactional revocation verified. Added Sanctum4.3.3 only. Audit found43 advisories in12 pre-existing packages; tracked separately as P2-005 before release/phase closure. |
| 2026-10-16 | P2-005 | IN PROGRESS: reviewed a compatible 13-package dry run with no additions/removals and a clean candidate audit. Captured the repository baseline before applying dependency changes; full regression and final audit remain pending. |
| 2026-10-16 | P2-005 | COMPLETE: 13 compatible package updates; zero locked audit findings; 356 tests / 3,117 assertions, actual array-mail rendering, platform/manifest/routes and preservation checks passed. Phase 2 repository gate closed; real-environment gates remain open. |
| 2026-10-16 | P3-001 | IN PROGRESS: recorded CLI-only read-only inventory/preflight contract; no ownership/schema changes. Tool verification will use isolated SQLite fixtures; live inventory, backup restore and owner mapping remain separate gates. |
| 2026-10-18 | P3-001 | COMPLETE: 22 focused / 378 total tests, read-only/privacy/preservation checks and CLI exits passed. Configured MySQL remains unavailable (sanitized exit 2); ownership/restore and real rollout remain unverified. |
| 2026-10-18 | P3-002 | IN PROGRESS: dormant additive schema, inactive memberships, protected false platform capability and guarded rollback contract recorded. No live migration/backfill; actual inventory/restore/mapping remain unresolved. |
| 2026-10-18 | P3-002 | COMPLETE: dormant schema, protected false capability, restrictive inactive memberships and guarded rollback verified; 410 tests / 4,249 assertions passed. No real DB rollout/backfill or tenant access enabled. |
| 2026-10-18 | P3-003 | IN PROGRESS: read-only explicit ownership/platform manifest contract recorded. No write/apply mode, inferred owner or privilege change; real mapping/restore/engine evidence stays unresolved. |
| 2026-10-18 | P3-003 | COMPLETE: strict private manifest and aggregate read-only eligibility/coverage verified; 529 tests / 5,151 assertions passed. Existing throttle bursts now use explicit test clocks after a wall-clock expiry failure. No ownership/grants or real DB changes. |
| 2026-10-18 | P3-004 | IN PROGRESS: private canonical plan identity and dormant resolution-ledger contract recorded; no writer, automatic reconciliation, actual migration or grants. |
| 2026-10-18 | P3-004 | COMPLETE: private canonical identity, empty guarded ledger and count-only inventory verified; 581 tests / 5,399 assertions passed. No real DB migration, writer, ownership or grants. |
| 2026-10-18 | P3-005 | IN PROGRESS: dormant per-workspace transactional backfill contract recorded; all execution remains isolated fixtures, with no CLI/HTTP/job entry point or actual owner/grant changes. |
| 2026-10-18 | P3-005 | COMPLETE: dormant per-workspace transactions, strict retries, schema/ID guards and conservation verified; 656 tests / 6,689 assertions passed. No application caller or configured database write. |
| 2026-10-18 | P3-006 | IN PROGRESS: separate loopback MySQL 8.4.11 synthetic rehearsal contract recorded; installed Oracle-signed binary/archive identified. No configured database, XAMPP or other project service/data changes. |
| 2026-10-18 | P3-006 | COMPLETE: actual isolated MySQL8.4.11 DDL/backfill/constraints/raw-ID/selected-lock checks and 31-table synthetic restart/restore passed; 656 tests / 6,689 assertions. Repaired historical FK-supporting index order; owned server stopped and plaintext credentials removed. Real rollout gates remain open. |
| 2026-10-18 | P3-007 | IN PROGRESS: read-only exact progress-inspection contract recorded. Share existing validation while retaining dormant writer boundaries, safe aggregates and all real rollout gates. |
| 2026-10-19 | P3-007 | COMPLETE: private read-only exact progress inspection and shared validation/reader passed; 61 added cases, 717 total tests / 10,483 assertions. No writer caller, grants or configured DB changes. |
| 2026-10-19 | P3-008 | IN PROGRESS: dormant fresh account/platform/membership context contract recorded; no action permissions, runtime caller, grant or activation. |
| 2026-10-19 | P3-008 | IN PROGRESS: primary-only metadata correction reproduced with a throwing-replica regression and fixed; corrected focused128 tests /2,549 assertions pass. Full regression pending; no runtime caller or existing-source change. |
| 2026-10-19 | P3-008 | COMPLETE: dormant strict primary-read account/platform/member context;78 added cases; 795 total tests / 12,771 assertions passed. No runtime caller, policy/action grants, activation or existing source changes. |
| 2026-10-19 | P3-009 | IN PROGRESS: separate synthetic MySQL multi-process contention/disconnect contract recorded; no configured DB, runtime writer or existing application source change. |
| 2026-10-19 | P3-009 | COMPLETE: four real MySQL cases/11 workers verified exact contention, sanitized deadlock refusal, worker-disconnect rollback and zero-DML retries. All 340 source baseline paths preserved; owned server/workers stopped and credentials cleared. Current795-test source gate retained. |
| 2026-10-19 | P3-010 | IN PROGRESS: current QR/manual admission capacity/history conservation contract on disposable MySQL; no service extraction, route/API change or customer-policy activation. |
| 2026-10-19 | P3-010 | COMPLETE: five actual MySQL cases / ten independent HTTP-kernel workers verified last-entry pairs, lock waits, exact history conservation and interrupted-counter rollback. All 341 baseline paths preserved outside docs; owned processes stopped and credentials cleared. |
| 2026-10-19 | P3-011 | IN PROGRESS: selected actual MySQL primary-read/revocation/caller-snapshot verification of the dormant authority resolver; no runtime policy or real grants. |
| 2026-10-19 | P3-011 | COMPLETE: 34 actual MySQL authority observations passed, including independent committed revocation, primary-only reads, caller RR/RC visibility and full read conservation. All 342 baseline paths preserved outside docs; owned server stopped and credentials removed. |
| 2026-10-19 | P3-012 | IN PROGRESS: source-aligned current-state notes and plain-language ownership/restore status; documentation only. |
| 2026-10-19 | P3-012 | COMPLETE: plain-language status and source-aligned current-state corrections reviewed; 343 baseline paths preserved outside docs and all 97 documented route methods/paths matched. |
| 2026-10-19 | P3-013 | IN PROGRESS: bounded legacy CSV formula hardening for existing admin reports/checkins; no tenancy/customer activation or stored-data rewrite. |
| 2026-10-20 | P3-013 | COMPLETE: five unsafe CSV downloads reproduced then guarded; 33 new HTTP cases and full 828 tests / 13,004 assertions passed. Existing data/filters/routes preserved; raw CSV prefix and spreadsheet-client limits documented. |
| 2026-10-20 | P3-014 | IN PROGRESS: fixed SELECT-only configured-connection availability refresh; no customer inventory, inferred owner, service startup or database mutation. |
| 2026-10-20 | P3-014 | COMPLETE bounded diagnostic: configured mysql unavailable / 2002 / exit 2 before SELECT; no customer inventory or mutation. All 346 source baseline paths preserved outside docs; actual rollout gates remain open. |
| 2026-10-20 | P3-015 | IN PROGRESS: default demo seeder environment/empty-target/transaction guard; replace destructive resets with safe refusal, verified only in isolated fixtures. |
| 2026-10-20 | P3-015 | COMPLETE: exact local/testing guard, empty-target refusal and transactional samples; 21 new cases, focused 41 tests / 678 assertions and full 849 tests / 13,574 assertions passed. Existing data/config/scripts preserved; recovery/existing-account risks remain open. |

| 2026-10-20 | P3-016 | IN PROGRESS: verify default-seeder refusal, original sample compatibility and failed-insert rollback on a separate synthetic MySQL 8.4.11 server. No application source or configured database change. |
| 2026-10-20 | P3-016 | COMPLETE: 16 actual synthetic MySQL seeder cases passed, including full failure rollback/retry and outside-environment/populated-target refusal. All 352 baseline paths preserved outside docs; server stopped and generated plaintext credentials removed. |

| 2026-10-20 | P3-017 | IN PROGRESS: add exact root portable-backup Git exclusion for recorded R09; verify real ignore behavior using invented paths and preserve the Git index/application/private backup data. |
| 2026-10-20 | P3-017 | COMPLETE: exact root backup-directory exclusion; all 11 path probes passed in TEMP and actual repository. Existing ignore bytes, index and 359-path baseline preserved outside scoped rule/docs; no backup contents inspected. |

| 2026-10-21 | P3-018 | IN PROGRESS: selected existing reset/reset and reset/device-login contention plus interrupted-reset rollback on a separate synthetic MySQL server; no application source or configured database change. |
| 2026-10-21 | P3-018 | COMPLETE: four actual MySQL recovery/token cases / eight independent HTTP workers; owned lock waits, exact credential/token conservation and interrupted-reset rollback/retry passed. All 359 source baseline paths preserved outside docs; owned processes stopped and plaintext credentials removed. |

| 2026-10-21 | P3-019 | IN PROGRESS: guard legacy recovery --apply outside exact local/testing before input/DB/hash work; verify only TEMP copies/spies and invented dry-run inputs. No real recovery or production provisioning. |
| 2026-10-21 | P3-019 | COMPLETE: exact effective-environment guard before recovery input/DB/hash work; 21 CLI cases, focused 42 tests / 972 assertions and full 870 tests / 13,976 assertions passed. Original writer/dry-run body and existing data/config preserved. |

| 2026-10-21 | P3-020 | IN PROGRESS: reject missing/empty/repeated and wrong-type recovery CLI paths with existing usage exit1 before file reads; preserve P3-019 guard and valid dry-run/parser/writer behavior. |
| 2026-10-21 | P3-020 | COMPLETE: strict recovery input shapes; twelve added CLI cases, focused 54 tests / 1,268 assertions and full 882 tests / 14,272 assertions passed. Prior guard/21cases/parser/writer preserved; no actual recovery. |

| 2026-10-21 | P3-021 | IN PROGRESS: refresh configured mysql availability with fixed SELECT1-only diagnostic; no customer inventory, ownership inference, service or data changes. |
| 2026-10-21 | P3-021 | COMPLETE diagnostic: configured mysql still unavailable /2002 /exit2 before SELECT. All 362 baseline paths preserved outside docs; actual database/ownership/restore/fencing gates remain open. |

| 2026-10-21 | P3-022 | COMPLETE: dormant automatic new-workspace/active creator-owner provisioning with atomic retry ledger; 938 tests / 15,017 assertions passed. No account/event/legacy ownership changes or customer route exposure. |

| 2026-10-21 | P4-001 | COMPLETE: five exact owner/manager actions, fresh primary membership and event-parent checks; 976 tests / 17,366 assertions passed. No runtime wiring, customer identity or legacy access changes. |

| 2026-10-21 | P4-002 | IN PROGRESS: explicit guest/event/workspace and QR/guest lineage observations; no runtime exposure, file serving or mutation. |
| 2026-10-22 | P4-002 | COMPLETE: four exact guest/QR actions with fresh event permissions and full primary lineage; 1,011 tests / 19,736 assertions passed. No runtime exposure, file serving or mutation. |

| 2026-10-22 | P4-003 | COMPLETE: neutral customer web identity, protected own-workspace home, coherent recovery/device denial and staff CRUD identity protection; 1,071 tests / 20,461 assertions passed. No signup or customer writer. |

| 2026-10-22 | P4-004 | COMPLETE: unowned-only legacy catalogue/layout and fresh locked direct event metadata controls; 1,110 tests / 21,253 assertions passed. Other selected/offline/resource paths remain pending. |

| 2026-10-22 | P4-005 | COMPLETE: primary unowned selected-event checks, no administrator default fallback, fresh direct offline entry and import-context invalidation; 1,172 tests / 21,736 assertions passed. Atomic downstream/file fences remain open. |

| 2026-10-22 | P4-006 | COMPLETE: protected staff targets, primary unowned event choices and Event-before-User transactional account checks; 1,240 tests / 22,336 assertions passed. Engine contention and remaining offline/scanner/file boundaries stay open. |

| 2026-10-22 | P4-007 | COMPLETE: four actual MySQL staff cases / nine workers, three exact primary-row waits and dedicated collation/unsigned-ID observations; owned server/workers stopped and plaintext credentials removed. P4-006 source gate retained. |

| 2026-10-23 | P4-008 | COMPLETE: MySQL automatic ownership: 10 cases / 13 workers, three exact creator waits, all insert-stage interruptions and actual unsigned allocator rollback; owned server/workers stopped and plaintext credentials removed. Source gate retained. |

| 2026-10-27 | P4-023 | IN PROGRESS: current scanner-account HTTP review projection; preserve canonical historical filter IDs and captured names while restricting current User models to eligible same-Event scanners. Actor policies remain separate. |
| 2026-10-27 | P4-023 | COMPLETE: six HTTP review graphs restrict current scanner models to ordinary same-Event accounts while preserving canonical historical IDs/captured metadata. Boundary 16 / 3,805, focused 663 / 12,408, full 1,764 / 33,752; all 77 prior classes, 100 routes/32 tables and unrelated source/environment/index/build preserved. Actor/authority and UI/RSVP work remain separate. |

| 2026-10-27 | P4-024 | IN PROGRESS: current Event/admin/Session/Import transaction prefix for HTTP confirmation; preserve internal reconciliation and completed retry, with generic late403/404 and no real-data rollout. |

| 2026-10-27 | P4-024 | COMPLETE: current Event/admin/parent checks enclose HTTP confirmation. New24/389, compatibility12/488, focused687/12,797, full1,788/34,141; all78 prior classes preserved. Source/environment/index/build and100-route/32-table checks pass. P4-025 remains a TEMP draft. |

| 2026-10-27 | P4-025 | IN PROGRESS: locked current Event/admin/Session/Import/Conflict authority for HTTP resolution; preserve underlying action/admission rules and defer nested log/Guest/sibling effects. |

| 2026-10-27 | P4-025 | COMPLETE: locked current Event/admin/Session/Import/Conflict checks for HTTP resolution. New23/364, compatibility10/277, focused710/13,161, full1,811/34,505; all79 prior classes conserved. Source/environment/index/build and100-route/32-table checks pass; bound-log/Guest/sibling effects remain separate. |

| 2026-10-27 | P4-026 | IN PROGRESS: strict scoped bound-Log resolution and shared safe Log relation; preserve action/admission rules and defer Guest/replacement/sibling effects. |

| 2026-10-27 | P4-026 | COMPLETE: strict same-Import/Session processing Log references, safe explicit relation and raw context guards. New19/324, workflow11/189, focused729/13,485, full1,830/34,829; all80 prior classes conserved. Source/environment/index/build and100-route/32-table checks pass; Guest/replacement/sibling work remains separate. |

| 2026-10-27 | P4-027 | IN PROGRESS: strict Event-scoped bound/replacement Guest processing with ID-only existence locks and QR hydration after safe Guest identity checks. |

| 2026-10-27 | P4-027 | COMPLETE: scoped locked bound/replacement Guests and QR hydration after raw identity checks. New15/231, workflow11/189, focused744/13,716, full1,845/35,060; all81 prior classes conserved. Source/environment/index/build and100-route/32-table checks pass; sibling/atomicQR/internal-return gates remain open. |

| 2026-10-27 | P4-028 | IN PROGRESS: identity-only open-sibling validation before action, frozen update IDs and repeated parent predicates; preserve null-log and escalation behavior. |

| 2026-10-28 | P4-028 | COMPLETE: validated/frozen OPEN sibling write set and repeated parent predicates, preserving null/escalation. New10/390, workflow11/189, focused754/14,106, full1,855/35,450; all82 prior classes conserved. Source/environment/index/build and100-route/32-table checks pass. |

| 2026-10-28 | P4-029 | IN PROGRESS: source audit/documented sequence for preliminary upload audits, preview commit/cleanup and secure-delete partial effects; no runtime change. |

| 2026-10-28 | P4-029 | COMPLETE audit: source transaction/file boundaries, R31–R33 and safe continuation documented. All360 non-doc paths/350 tested-source fingerprints preserved;100-route/32-table CLI and85-doc/1,267-link gates pass. Retains P4-028 1,855/35,450 result; no new runtime/file effect or backend rerun. |

| 2026-10-28 | P4-030 | IN PROGRESS: separate current-authority transactions for external preliminary upload audits, preserving failure traces and existing preview/file transaction boundaries. |

The earlier audit is a dated baseline. Later runtime changes and verification must be recorded separately; an in-progress row does not claim a feature is complete or deployed.

| 2026-10-23 | P4-009 | COMPLETE: existing customers can create an empty workspace with automatic owner, safe replay and locked customer-role checks. Full 1,275 tests / 22,874 assertions; 100 routes. No event/signup exposure or configured data change. |

| 2026-10-23 | P4-010 | COMPLETE: 49 new offline parent-entry cases; full 1,324 tests / 23,668 assertions. Private/inconsistent heads deny before policies/payload/validation/effects. Routes unchanged; later atomic/file boundaries remain separate. |

| 2026-10-23 | P4-011 | COMPLETE: 48 new package-generation cases; full 1,372 tests / 23,870 assertions. Event/User/membership checks run under existing transaction locks, private scanner choices are excluded and SQL failure rolls back/cleans its file. Engine follow-up remains. |

| 2026-10-23 | P4-012 | COMPLETE: six actual MySQL groups / nine workers / four exact row waits; current eligibility, production generation and SQL/file rollback/retry passed. Source gate 1,372 tests / 23,870 assertions retained; owned server and credentials cleaned up. |

| 2026-10-23 | P4-013 | COMPLETE: 110 new locked session-action cases; full 1,482 tests / 24,782 assertions. Current parent/actor/lifecycle checks and audit rollback/generic failure passed; internal workflows preserved. |

| 2026-10-23 | P4-014 | COMPLETE: 83 delivery cases; full 1,565 tests / 25,642 assertions. Buffered delivery, current row checks and precommit metadata/audit rollback across three existing paths; committed generation preserved on delivery failure. |

| 2026-10-24 | P4-015 | COMPLETE: 88 archive cases; full 1,653 tests / 26,523 assertions. Current parent/actor/archive policy, atomic metadata/audit and no file IO verified; internal helper bodies preserved. |

| 2026-10-24 | P4-016 | COMPLETE: four generation identity cases / 31 new assertions; full 1,657 tests / 26,554 assertions. Original/current actor consistency refuses before row queries; existing behavior preserved. |

| 2026-10-24 | P4-017 | COMPLETE: nine deletion-result cases; full 1,666 tests / 26,689 assertions. False delete results refuse before metadata/audit success; existing controls preserved. |

| 2026-10-25 | P4-018 | COMPLETE: eight SQL/storage feedback cases; full 1,674 tests / 26,907 assertions. Generic deletion feedback, exact existing partial effects and prior behavior verified. |

| 2026-10-25 | P4-019 | COMPLETE: ten database/business feedback cases; full 1,684 tests / 27,184 assertions. PDO-only safe feedback, existing precommit rollback and business messages verified. |

| 2026-10-25 | P4-020 | IN PROGRESS: preview Guest Event scope, redacted wrong-Event context and canonical-ID review behavior. |

| 2026-10-26 | Customer experience / RSVP requirement merge | Documentation integration complete: 23 documents, eight reference mappings and 18 NOT STARTED tasks added within existing phases. All 1,175 local links resolve; 339 baseline non-doc files unchanged. P4-020 remains IN PROGRESS; no runtime/schema/API activation or new backend test result. |

| 2026-10-26 | All-interface simplicity clarification | Documentation follow-up complete: nine documents align the shared Kadify visual language and task simplicity across all interfaces. Added P6-UX-02 rollout and P27-UX-02 usability gates, bringing the additive table to 20 NOT STARTED tasks. All 1,186 local links resolve; 339 baseline non-doc files unchanged. P4-020 remains IN PROGRESS; live UI changes follow owning phases. |

| 2026-10-26 | P4-020 | COMPLETE: Event-scoped preview Guest models, strict positive IDs, redacted moved-snapshot classification and raw review keys. Boundary 36 tests / 866 assertions; focused 619 / 6,706; full 1,720 / 28,050. All 74 prior test classes retain exact counts/assertions; 100 routes/32 tables and unrelated source/environment/index/build preserved. Broader authority/history/file gates remain open. |

| 2026-10-26 | P4-021 | IN PROGRESS: scope confirmation processing Guest models/current state and final-state mutations to the validated locked Event; preserve moved-Guest classification with ID-only existence and strict raw snapshot IDs. Historical return/review relations and broader authority/file fences remain separate. |

| 2026-10-26 | P4-021 | COMPLETE: confirmation processing Guest models and count effects are Event-scoped; moved identities retain redacted WRONG_EVENT. Boundary 12 tests / 488 assertions; focused 631 / 7,194; full 1,732 / 28,538. All 75 prior classes retain exact counts/assertions; 100 routes/32 tables, unrelated source/environment/index/build conserved. Historical/eager relations and broader authority/file gates remain open. |

| 2026-10-26 | P4-022 | IN PROGRESS: read-only HTTP review Guest/replacement/final-state projection and historical current-state provenance; preserve own-Event history without rewriting stored rows. Scanner/actor projections and internal service return graphs remain separate. |
| 2026-10-26 | P4-022 | COMPLETE: Event-scoped current Guest review relations and attributed historical server-state display; boundary 16 / 1,409, focused 647 / 8,603, full 1,748 / 29,947. All 76 prior classes, 100 routes/32 tables and unrelated source/environment/index/build preserved. No stored history/private-file repair or UI/RSVP implementation. |

## Task P0-001 — COMPLETE (2026-10-11)

Objective: establish concise canonical references and the requested specialist documentation structure from the existing application.

Acceptance criteria:

- Create the four canonical architecture, project-plan, API and database documents and a linked documentation index.
- Cover identity, tenancy, design/localization, invitations, scanner/offline, messaging, hosting, testing and operations without claiming planned features exist.
- Preserve and link equivalent existing notes; record four architecture decisions and concrete next-task contracts.
- Validate local references, documentation states and whitespace while preserving application behavior.

Evidence: 43 Markdown references and 372 local links validated; no broken paths or missing state markers; scoped whitespace check passed. Changes were documentation only. External runtime limits are explicit in the [Phase 0/1 report](PHASE_01_REPORT.md).

## Task P1-001 — COMPLETE (2026-10-11)

Objective: audit the working Laravel application, identify reusable modules/risks, and define a safe SaaS migration sequence.

Acceptance criteria:

- Inspect code, routes, schema/migrations, auth/roles, admin/scanner/admission, QR/manual codes, offline, notification, invitation, UI/localization, deployment and tests.
- Verify installed runtime and registered routes; run isolated backend and temporary build checks without touching the configured database or existing build.
- Reproduce material login defects safely, document contradictions and distinguish missing Android/live-hosting/visual evidence.
- Define ownership migration requirements and exact Phase 2 contracts while preserving existing data and uncommitted work.

Evidence: PHP 8.2.12 / Laravel 12.58.0 and 88 baseline routes; 196/197 existing tests passed with one recorded HTTPS redirect failure; 2 diagnostic auth probes / 5 assertions and temporary build passed. All 230 checked non-doc files remained unchanged. Actual DB connection failed (2002); Android, browser visuals and cPanel remained unverified. Completion is the bounded repository audit, not certification of unavailable environments.

## Task P2-001 — COMPLETE (2026-10-12)

Objective: repair shared browser authentication while preserving the working administrator/scanner role boundaries and existing login action.

Acceptance criteria:

- Restore nullable Laravel remember-token storage through an additive migration; keep password hashes/IDs intact and hide the token from serialization.
- Prove remember-cookie identity restoration, HTTP-only cookie handling, logout expiry and remember-token rotation.
- Accept only same-origin or root-relative intended URLs within the authenticated account's permitted area; preserve valid queries and reject external, malformed, encoded/traversing and wrong-role destinations.
- Clear selected-event/import-preview state on fresh login and old intended context on account replacement; retain the current identity/context when replacement credentials fail.
- Add clearly labelled `/admin/login` and `/scanner/login` entry pages sharing POST `/login`; entry labels grant no extra role or customer access.
- Run scanner HTTPS redirection before authentication, preserving path/query and localhost exemption; retain secure scanner JSON authentication and existing role denials.
- Display escaped session-expired feedback, preserve blank passwords on failed login, add browser autocomplete and remove public demo credentials from the form.
- Run focused regressions, the complete backend suite, PHP syntax and task-scoped whitespace checks; update affected architecture/auth/API/database/security references and record deployment limits.

Verification evidence:

- Full isolated SQLite suite: **227 tests, 1,768 assertions passed**, 53.628 seconds. The former scanner HTTPS baseline failure now passes.
- Focused run initially passed 45/46 checks; the remaining failure was a logout fixture that omitted the incoming browser cookie. Corrected fixture and account-context checks then passed **2 tests / 30 assertions** before the full suite.
- PHP syntax and scoped `git diff --check` passed. Hash comparison found exactly seven changed/new task files among 232 checked files; `.env`, existing `public/build` and pre-existing unrelated work remain intact.
- Documentation validation passed: 43 Markdown files / 372 local references, no broken links or missing state markers at this task gate.

Files: AuthController, User, bootstrap middleware priority, web routes, login view, new remember-token migration and new AuthSessionFoundationTest. Database impact: migration prepared and exercised only in memory; actual MySQL rollout remains unverified. API/UI impact: two additive browser login entry routes and login feedback/copy; scanner JSON/admission contracts unchanged. Security impact: remember/redirect/HTTPS defects repaired in source/tests; deployed demo-account and backup risks remain open. Localization/responsive checks: source/HTML tests only; full bilingual visual/device matrix was not run. No dependency upgrade, real message send or deployment occurred.

Recommended next task: P2-002. Later phases remain incomplete until their own acceptance evidence is recorded.

## Task P2-002 — COMPLETE (2026-10-12)

Objective: support email or Tanzanian phone plus password with one shared account, while enforcing persisted account availability and preserving existing administrator/scanner workflows.

Acceptance criteria:

- Add nullable unique canonical account `phone_number`, active default and stored sw/en language preference through an additive migration; leave legacy phones null and guest data untouched.
- Reuse PhoneNumberService through one LoginIdentifier helper; accept equivalent local/country/international phone forms and preserve existing email requests/storage semantics.
- Return generic credential errors for invalid, unknown, inactive or unsupported-role accounts; retain correct password/remember behavior.
- Reload persisted account state/role before protected web actions; reject disabled/deleted identities and remembered sessions, and apply role changes to cached guards.
- Enforce independent IP and canonical-identifier login limits so phone-format or IP switching cannot bypass their respective controls.
- Provide optional phone/language/account-state management with canonical uniqueness, omitted-field preservation, truthful inactive status and prevention of own-account disabling.
- Reject inactive master/client scanners in new offline-package UI, request validation and locked builder eligibility; preserve historical package/result import behavior.
- Run focused/full backend checks, new-file formatting, syntax/whitespace and a temporary frontend build; record environment and localization limits.

Verification evidence:

- Full isolated suite: **265 tests / 1,946 assertions passed**, 115.866 seconds.
- Initial focused run passed 80/84 tests. Corrected raw database-token assertions and enabled cookies in the remembered JSON request; affected **4 tests / 17 assertions passed** before the full regression.
- Pint passed for all six new PHP files after two formatting fixes; source syntax and scoped whitespace checks passed. Temporary Vite build passed (82 modules, 14.16 seconds).
- Hash comparison: 17 task files changed/new among 238 checked files; `.env`, existing compiled assets, dependencies and unrelated source matched the previous task gate. Runtime registration remains 90 routes; login uses the named limiter.

Files: shared-identity migration/helper/middleware, User/auth/provider/bootstrap/routes, admin user controller/form/list, login view, two offline provisioning boundaries and three new test files. Database impact: prepared nullable account fields/defaults/unique index, exercised in memory only. UI/localization: email-or-phone field and account settings; language preference is stored, while translated UI and browser viewport checks remain planned. API: browser compatibility retained, bearer API not yet implemented. Security: inactive online/new-package access denied; already downloaded isolated packages cannot be remotely revoked, and unsynced history remains recoverable. No real database migration, email/WhatsApp send, dependency change or deployment occurred.

Recommended next task: P2-003 email recovery; actual SMTP/MySQL/hosting/Android verification remains open.

## Task P2-003 — COMPLETE (2026-10-14)

Objective: provide registered-email password recovery from an email or Tanzanian phone identifier, and revoke old browser/remember access after password changes while preserving the existing scanner and offline workflows.

Acceptance criteria:

- Reuse Laravel's users password broker and existing reset-token table with 60-minute expiry, 60-second issuance throttle, hashed tokens and single-use reset.
- Give known, unknown, malformed, inactive and unsupported accounts the same public request response; send only to the registered email through a common deferred delivery path.
- Add forgot/reset forms and the login link, use trusted APP_URL links, escape form content, keep passwords/tokens out of flashed input and protect forms with CSRF and no-store/no-referrer headers.
- Enforce independent IP and canonical-identifier limits for recovery/reset; reset limits must use the actual submitted email even when an unrelated identifier is supplied.
- Refuse log/composite mail transports including URL overrides and cycles; sanitize delivery failures without logging recipient, reset URL, exception message or trace.
- Atomically update the password, rotate remember credentials and consume the token; roll back on failure and require fresh login rather than automatically authenticating the reset browser.
- Reject stale browser password markers and remembered credentials, require one-time login for legacy sessions without a marker, and preserve valid remember restoration into a fresh session.
- Run focused/full backend checks, formatting, syntax, route registration and a temporary asset build; document deployment and offline limits.

Verification evidence:

- Focused recovery suite: **40 tests / 228 assertions passed**, 14.066 seconds. Full isolated suite: **305 tests / 2,174 assertions passed**, 63.743 seconds.
- Coverage includes all supported phone forms, unknown/malformed accounts, expiry/replay, invalid passwords, IP/identity throttling, Host-independent links, notification failures after token creation, transactional rollback, CSRF enforcement, cross-browser revocation, fresh remember restoration and hash-shaped chosen passwords.
- Review corrected two implementation defects: an extra identifier could change reset throttle keys, and a hash-shaped chosen password could be mistaken for a stored hash by the model cast. Regression checks now cover both. An initial rollback fixture omitted its guarded remember token; the fixture was corrected before the passing full run.
- Pint passed for five task PHP files; eight PHP syntax checks and scoped whitespace checks passed. Runtime registered **94 routes**, including the four password routes. Temporary Vite build passed: **82 modules, 6.17 seconds**; existing public/build was not replaced.
- Tests used SQLite memory, array sessions/cache/mail, fake notifications/HTTP and temporary Laravel storage. An initial slow test invocation was interrupted without a result; only the completed runs above count as evidence.
- Documentation checks passed for 44 Markdown files / 384 local links and updated state markers. Comparison with the 277-path resume snapshot preserved .env, dependency manifests/locks and unrelated checked non-doc files.

Files: recovery controller, deferred delivery class, password-session middleware, AuthController/provider/bootstrap/web routes, login/forgot/reset views and PasswordRecoveryTest; related canonical/specialist references updated. No migration or dependency change was needed. API tokens do not exist at this gate: P2-004 must add their password-reset revocation. Existing offline packages and historical result imports remain unchanged.

Limits: actual SMTP delivery, response flushing/durability on cPanel, MySQL locking under simultaneous resets, secure production cookies, web-server/proxy reset-URL log redaction, browser viewport/localization and Android behavior remain unverified. Deferred delivery can be lost if the process dies. A safe dedicated mail transport and trusted HTTPS APP_URL must be verified before rollout. No real database migration, email/WhatsApp send or deployment was performed.

Next task: P2-004 secure device tokens, then the Phase 2 report/gate. Phase 2 is not yet complete.

## Task P2-004 — COMPLETE (2026-10-16)

Objective: add same-account HTTPS device-token login, identity and logout without changing browser/scanner authentication or offline admission history.

Acceptance criteria:

- Verify a compatible supported token facility and add only Sanctum4.3.3, with an additive personal_access_tokens migration and hashed token storage.
- Provide POST /api/v1/auth/login, GET /api/v1/auth/me and POST /api/v1/auth/logout, requiring HTTPS and bearer authentication independently of web sessions/CSRF.
- Accept shared email/phone/password and a bounded device label; return generic credential failures and independently throttle IP/canonical identifier.
- Issue only auth:read/auth:logout abilities, explicit24-hour expiry and a maximum of10 active tokens per account; return the secret once, preserve exact response shapes and private/no-store headers.
- Recheck persisted active supported-role identity and explicit active scanner assignment; reject missing/default/foreign input assignments and retain current-token logout when event access is lost.
- Serialize issuance with password recovery/admin changes using the user row; revoke all account tokens on recovery and administrative password/availability/role/event/gate changes, with transactional rollback.
- Prove cookie/bearer isolation, expiry/replay, current-device logout, safe error responses and unchanged existing web/scanner/offline behavior; update API/database/device/security references.

Verification evidence:

- Full isolated regression passed **353 tests / 3,049 assertions** (124.386315s), including **48 tests / 875 assertions** in DeviceTokenAuthenticationTest. New-file Pint, 15 PHP syntax checks and scoped whitespace checks passed. Runtime registers97routes: the prior94 plus the three versioned auth endpoints; no Sanctum CSRF-cookie route is enabled. Tests use SQLite memory, fake notifications/HTTP and temporary Laravel storage. Existing public/build, .env and unrelated checked source are preserved.
- Initial focused run passed46/47 cases; its sole failure assumed an empty events table despite the legacy default-event migration. Corrected to compare the pre-request count; three affected/expiry checks then passed before the full regression.
- Review added gate-only assignment revocation and preserved the API envelope/privacy headers through the legacy419 responder. Tests cover exact24-hour expiry, earlier explicit expiry, unknown/malformed inputs, limiter independence, ten-token pruning, abilities, account/event changes, reset/admin revocation and failure rollback.
- Composer lock comparison adds only Sanctum4.3.3, with no other package version change/removal. Schema validation is valid; strict mode reports the intentional exact-version-pin warning. Dependency audit is **not clean**:43 advisories across12 existing packages, none for Sanctum; P2-005 addresses this release blocker separately.

Files: device API controller, token service, bearer/security middleware and response helper; API routes/config and token migration; User/provider/bootstrap/admin/recovery integration; Composer manifests/lock and focused tests. No frontend/Android implementation or real database migration occurred. Login-issued token data stays outside logs/URLs; device labels are metadata, not proof of physical device ownership.

Limits: SQLite does not prove concurrent MySQL lock ordering. Deployment must rehearse the additive migration, HTTPS/trusted proxies, expiration cleanup, token storage and Android interoperability. Direct SQL/future credential writers must participate in the documented revocation boundary. Disconnected packages and pending result history remain unchanged. No live message send or deployment was performed.

Next task: P2-005 dependency remediation, then the Phase 2 repository report. Production/mail/Android gates remain separate.

## Task P2-005 — COMPLETE (2026-10-16)

Objective: resolve the reported locked dependency advisories with PHP 8.2 / Laravel 12-compatible updates while preserving existing authentication, scanner, offline and export behavior.

Acceptance criteria:

- Review a targeted Composer dry run and update only affected package families and required dependencies, with no major framework/PHP upgrade or advisory/platform bypass.
- Preserve the reviewed Sanctum 4.3.3 lock while using the compatible ^4.3.3 manifest constraint to pass strict Composer validation.
- Install the reviewed lock with scripts/plugins disabled; run only controlled package discovery with isolated configuration.
- Prove a clean locked audit, manifest validity, installed/locked version agreement and PHP platform compatibility.
- Exercise actual reset-email Markdown/MIME rendering through array mail, registered recipients, trusted reset links and malformed-address rejection without external delivery.
- Run the complete isolated regression, route registration, syntax/formatting and preservation checks; record actual hosting/database/mail limits separately.

Verification evidence:

- Full isolated regression passed **356 tests / 3,117 assertions** (138.676902s). Focused real-mail rendering checks passed **3 tests / 68 assertions** (1.745481s). Composer locked audit reports zero advisories, abandoned packages or filtered findings; strict manifest validation and locked PHP platform checks pass. Package discovery and all 97 routes boot successfully. New-test Pint, PHP syntax and scoped whitespace checks pass.
- Exactly 13 packages updated: the 12 packages with reported advisories plus Guzzle's required Promises dependency; no additions/removals. Laravel is now 12.70.0; Sanctum remains 4.3.3. [Versions and advisory evidence](DEPENDENCY_REMEDIATION.md).
- Both valid real-mail cases passed on the first focused run. The malformed-address fixture initially expected Symfony's exception, but updated Laravel rejects line breaks earlier with PHP's InvalidArgumentException. Corrected that expectation; no application compatibility code change was needed.
- Compared 294 baseline paths: .env, existing public/build and all pre-existing application source/tests are preserved. The only application-tree addition is PasswordRecoveryMailRenderingTest.php; dependency manifests/lock and evidence docs changed. Tests use SQLite memory and temporary Laravel storage; no actual database, SMTP/WhatsApp delivery or deployment was used.

Limits: this is a dated dependency audit and local repository gate, not proof that all security risks are resolved. MySQL migrations/concurrency, backups/ownership mapping, production SMTP/proxies, cPanel, Android and browser/device checks remain open. No frontend build was repeated because source/assets did not change; existing assets were hash-verified.

Phase 2 repository gate: COMPLETE. All five task contracts have successful named checks. Next: open P3-001's data-inventory/preflight contract before workspace schema or ownership changes; never infer owners from existing global admins.

## Task P3-001 — COMPLETE (2026-10-18)

Objective: provide a repeatable read-only CLI inventory for legacy migration prerequisites and core integrity findings, preserving records and withholding personal data and secrets.

Acceptance criteria:

- Require an explicit configured SQLite/MySQL/MariaDB connection; report unavailable/unsupported/invalid invocations as sanitized JSON with nonzero status, without changing the default connection.
- Inventory 27 known tables, required columns, row counts, migration history and selected unique indexes; detect incomplete prerequisites and unknown/pending migration history without creating a migration table.
- Count core guest/event/QR/admission relationship problems, invalid four-digit codes and pass/usage/quantity bounds, duplicate groups, scanner assignment gaps and nullable historical links without inferring repairs or ownership.
- Validate unique non-null streaming IDs before chunked checks; verify raw MySQL index parts so expression-bearing indexes cannot falsely prove single-column uniqueness.
- Keep aggregate reports free of names, phones, codes/tokens, credentials, payloads, database/file paths, unknown migration names and raw errors; retain explicit unverified ownership/restore/MySQL gates and rollout_ready=false.
- Prove repeatability, read-only SQL, full schema/row preservation, empty and malformed schemas, failure privacy and unchanged browser/scanner behavior through focused/full isolated checks.

Verification evidence:

- Full isolated regression passed **378 tests / 3,963 assertions** (148.420344s). Focused inventory checks passed **22 tests / 846 assertions** (1.284131s). Command discovery, missing-connection and empty-schema CLI smoke checks passed with their required nonzero exits; registered HTTP routes remain 97. New-file Pint, PHP syntax and scoped whitespace checks passed.
- Original focused run passed **19 tests / 810 assertions**. Independent review then found two potential false-clean results: normalized MySQL metadata could omit functional-index parts, and chunkById could skip duplicate IDs in a malformed schema. Both are guarded and covered by additional focused cases. The first expanded run exposed a test-double constructor setup error, corrected before the final passing checks. MySQL metadata semantics are simulated locally; no real MySQL compatibility claim follows.
- Full before/after snapshots include SQLite schema and all table contents. Tests verify no default event is created, no write SQL is issued by the inventory, explicit connections stay isolated, private fixture values are absent, and null historical links are review counts rather than automatic corruption.
- A read-only run against the configured MySQL connection returned sanitized database_unavailable with exit 2. No live schema, row counts, ownership, backup or restore result was established. No credentials or original exception details were printed or logged by the command.
- Compared 296 baseline paths: all pre-existing non-doc source, .env, Composer/npm manifests/locks and public/build remain intact. Four new PHP files contain the command/service and focused coverage. No configured database migration, real message send, dependency change, HTTP/UI feature or deployment occurred.

Files: TenancyPreflight command, LegacyDataInventory service, TenancyPreflightTest and TenancyPreflightMalformedSchemaTest; [inventory guide](LEGACY_DATA_INVENTORY.md), [Phase 3 report](PHASE_03_REPORT.md) and related canonical/specialist references. Offline tables are counted only; exhaustive provenance, constraints and files remain outside this bounded diagnostic task.

Limits: observations are best effort, not an atomic database snapshot. SQLite and simulated metadata cannot prove actual MySQL/MariaDB behavior, concurrent writes or deployed permissions. Actual inventory, backup restoration and legitimate owner mapping remain required before rollout/backfill. Phase 3 remains IN PROGRESS.

Next task: P3-002 additive workspace/membership schema contract and isolated verification; retain nullable legacy ownership and existing staff/scanner behavior. Real rollout/backfill stays gated on inventory, restore and reviewed mapping evidence.

## Task P3-002 — COMPLETE (2026-10-18)

Objective: prepare a dormant additive workspace/membership schema and protected platform capability while preserving legacy data, authentication and scanner authority.

Acceptance criteria:

- Add minimal workspaces and workspace_users with numeric keys, required owner/manager role, inactive membership default, unique membership pair and restrictive parent links.
- Add nullable indexed event workspace ownership and a false platform-admin capability without inferring or inserting owners, memberships, assignments or grants.
- Add explicit data relationships while preserving global role helpers, scanner event/gate fields and legacy routes; block mass-assignment and existing HTTP/API injection of protected ownership/capability fields.
- Preserve all prior row values and references through a populated isolated legacy upgrade, including QR/manual identifiers, counters, credentials and offline event relationships.
- Refuse conflicting expansion and unsafe SQLite transaction use before DDL. Allow only empty schema reversal; preserve all schema/data when memberships/workspaces/owned events/nonfalse or unknown platform data exist.
- Expand bounded inventory expectations to 29 tables and new fields; keep rollout readiness and external ownership/restore gates unresolved.
- Run focused/full isolated regression, formatting/syntax, route/CLI/whitespace and preservation checks; update affected canonical/specialist references.

Verification evidence:

- Full isolated regression passed **410 tests / 4,249 assertions** (111.846082s). Focused workspace/inventory checks passed **54 tests / 1,132 assertions** (2.762719s). Eight task PHP files passed Pint and syntax checks; scoped whitespace checks passed. Runtime retains 97 HTTP routes, and the empty-database CLI returns incomplete/exit 2 with the expanded 29-table catalog.
- Initial focused run passed 45/54 cases; nine errors shared one rollback-order defect: SQLite retained events_workspace_id_index while the column was being removed. Replaced dropConstrainedForeignId with explicit foreign-key → index → column removal. The corrected 54-case run passed before the full regression.
- Populated upgrade and empty reversal/reapply tests compare legacy row values and PRAGMA foreign_key_check; guard tests compare complete schema and all rows before/after refusal. Constraints reject duplicate/invalid/orphan membership and protected parent deletion. Both current/inactive membership data and unchanged scanner/admin access are covered.
- Protected-field tests prove existing admin HTTP and token login cannot grant/remove platform capability or assign/transfer event ownership; profile serialization remains allowlisted. A scanner workspace owner keeps scanner authority, and a global admin without membership retains legacy access.
- Compared 303 baseline paths: .env, public/build, dependency manifests/locks and unrelated source remain intact. Four existing PHP files changed narrowly (User/Event relationships, inventory catalog and its expected table count); four PHP files were added. Formatting also normalized blank-line separation of model constants.

Files: new workspace migration/model and two test classes; User/Event and inventory/test integration; related architecture, domain, database, tenancy, roles, security, deployment and rollback references. [Phase evidence](PHASE_03_REPORT.md), [migration contract](phases/PHASE_03_WORKSPACES.md).

Limits: Actual MySQL/MariaDB DDL, restored backups and legitimate owner/platform mapping remain unverified; configured MySQL was unavailable at the preceding inventory gate. No configured database migration, backfill, real message, frontend/dependency change or deployment occurred. SQLite fixtures do not certify live engines or concurrent DDL. Tenant policies, active-membership enforcement and platform-capability enforcement are separate tasks before customer exposure. DDL is non-atomic; partial failures require review/forward repair. Roll back code while retaining populated expansion; do not bypass the down guards or delete new rows to force reversal. New restrictive membership links need deliberate future user-retention workflows.

Phase 3 remains IN PROGRESS. Next: P3-003 explicit ownership/platform mapping preflight and backfill contract, with no guessed legitimate owners or actual rollout until external gates are satisfied.

## Task P3-003 — COMPLETE (2026-10-18)

Objective: validate an explicit private ownership/platform proposal and report bounded database eligibility and complete unowned-event coverage without changing owners, memberships or privileges.

Acceptance criteria:

- Require exact version-1 JSON objects/arrays, explicit positive integer IDs and nonempty per-workspace owners/events; reject ambiguous duplicate/escaped object keys, duplicate mappings, invalid names/types, overflow and resource-limit violations.
- Bound local regular-file reads to 256 KiB, JSON depth 8, 1000 groups and 10000 total ID occurrences. Reject wrappers/URLs, UNC paths and invalid files before database work; require an explicit configured connection.
- Check known schema and safe primary keys before selected lookups; batch account/event reads at 500 IDs. Require active supported owners, explicitly selected active legacy admins for platform proposals, and every unowned event including default/archived/cancelled records.
- Emit aggregate findings only with safe JSON exits 0/1/2; exclude private IDs/names/references/content/digests/paths and raw errors/logs. Every result remains read_only, rollout_ready=false with all real mapping/grant/restore/engine gates unverified.
- Prove read-only SQL, full database/file conservation, repeated output, explicit connection isolation, malformed schema, eligibility/coverage and multi-batch boundaries. Keep legacy HTTP/scanner authority unchanged.
- Run focused/full isolated regression, formatting/syntax, CLI/routes, whitespace and preservation checks; record evidence and actual-environment limits.

Verification evidence:

- Full isolated regression passed **529 tests / 5,151 assertions** (81.998851s). Focused ownership/throttle checks passed **127 tests / 1,005 assertions** (17.760108s). Nine task PHP files passed Pint and syntax checks. Command discovery, explicit missing-option/empty-schema exit 2 and all 97 HTTP routes passed; source whitespace and baseline preservation checks passed.
- Added 119 ownership parser/service/CLI cases with private-data markers, exact limits, semantic type/duplicate rejection, unsafe primary keys, selected account rules, all-event coverage and queries across 500-ID boundaries. Independent parser, command/path and database-service reviews found no blockers.
- The initial full run completed 529 tests with two failures: existing throttle bursts lasted 175.049377s and 72.939847s, exceeding their real 60-second windows. Eight existing throttle tests now freeze the test clock; the two affected cases also advance 61 seconds and verify expiry. Runtime rate-limit code is unchanged. Corrected focused/full runs passed.
- Compared 307 task-start baseline paths: all pre-existing application source, .env, compiled assets and dependency manifests/locks remain preserved. Only three existing test files changed for explicit clocks; six PHP files and this task's operator guide were added.

Files: OwnershipManifest, LegacyOwnershipPreflight and OwnershipPreflight command; parser/database/command tests, three deterministic throttle-test adjustments, [operator guide](OWNERSHIP_MAPPING_PREFLIGHT.md), [phase evidence](PHASE_03_REPORT.md) and affected canonical/specialist references.

Limits: No configured database migration/backfill, ownership inference, real grant, live manifest, external message, frontend/dependency change or deployment occurred. Observations are best effort, not an atomic snapshot or authorization. Actual inventory, legitimate owners/platform approval, database/file restoration and MySQL/MariaDB rehearsal remain unverified. Phase 3 stays IN PROGRESS; a durable ledger and transactional/reconciled writer are separate future work.

## Task P3-004 — COMPLETE (2026-10-18)

Objective: prepare a deterministic private proposal identity and dormant durable workspace-reference resolution ledger for a later separately reviewed backfill writer.

Acceptance criteria:

- Canonicalize validated version-1 proposals with fixed fields, bytewise references and exact integer ID ordering; preserve exact names and workspace associations, and apply the fixed domain plus NUL before SHA-256.
- Keep digest private from ordinary JSON/string conversion, CLI output and logs; make no approval, write or authorization decision from identity alone.
- Add empty legacy_ownership_plans and legacy_workspace_mappings structures with required version/hash/reference/links, unique hash/plan-reference/target workspace and restrictive foreign keys; insert no records or grants.
- Reject unsupported/active-transaction/conflicting/partial schema and unsafe workspace parent keys before DDL. Reverse only complete empty ledger tables, child first; preserve any populated ledger during code rollback.
- Extend bounded inventory to 31 tables using counts/known column gaps only. Preserve private hashes/references, all legacy records and current authority; exercise historical migrations in correct reverse chronology on isolated clones.
- Run focused/full isolated regressions, syntax/formatting, routes/CLI, whitespace and baseline conservation; record actual-environment limits.

Verification evidence:

- Full isolated regression passed **581 tests / 5,399 assertions** (156.775272s). Focused ledger/workspace/inventory checks passed **106 tests / 1,380 assertions** (78.078061s). Nine task PHP files passed Pint and syntax checks. All 97 HTTP routes and the expanded 31-table empty-schema CLI smoke passed with required exit 2; source whitespace and baseline preservation checks passed.
- Added 20 identity cases including an independently pinned Node-crypto known answer, protocol byte/order invariance, all meaningful mapping changes, exact whitespace/Unicode names and PHP_INT_MAX neighbors. Original validated input remains unchanged; implicit string conversion is unavailable and JSON stays empty.
- Added 30 migration cases with full schema/all-row snapshots, populated workspace/member/event/platform/guest/QR/manual/admission/offline conservation, 12 rejected constraint operations, explicit named connections, empty reversal/reapply and every populated/partial/table/view/transaction/unsupported guard. Review strengthened the parent check beyond column presence to declared id primary key plus observed nonnullness.
- Added private count-only inventory coverage and narrow expected-count/historical-fixture integration. Compared 314 task-start baseline paths: .env, build assets, dependencies and unrelated source remain unchanged. Four existing PHP files changed narrowly; five PHP files were added.

Files: OwnershipPlanIdentity and unit tests, ledger migration and integration tests, inventory catalog/expected-count/historical clone adjustments; [private ledger guide](OWNERSHIP_LEDGER.md), [phase evidence](PHASE_03_REPORT.md) and affected references.

Limits: No configured database migration, actual manifest persistence, backfill, real ownership/member/platform change, external message, frontend/dependency change or deployment occurred. Ledger identity is not approval, provenance or complete event/member/platform outcome journaling; source preparation does not establish resumable/idempotent application. Actual owner/platform authorization, inventory, database/file restore and MySQL/MariaDB DDL/concurrency remain unverified. Phase 3 remains IN PROGRESS.

## Task P3-005 — COMPLETE (2026-10-18)

Objective: prepare a dormant transactional workspace backfill service with exact same-proposal verification and isolated failure/conservation evidence, without exposing or executing a real writer.

Acceptance criteria:

- Accept explicit connection/validated private manifest only; reject platform-grant intent, ambient transactions, unsupported/nontransactional engines, unsafe schemas/keys/allocators, incomplete uniques/FKs and disabled enforcement before writes.
- Validate every selected owner/event, exact existing resolution and complete unowned-event coverage before inserting a plan. Never adopt a workspace by name, silently reconcile changed proposals or infer legitimate owners.
- Commit each new workspace's exact inactive owner memberships, listed-null event ownership and ledger mapping atomically; lock/revalidate selected rows and require exact conditional-update counts. Reread stored resolution/name/member/event state before commit.
- Verify existing same-proposal groups without rewrites/timestamp changes; preserve earlier commits after later failure and create only remaining eligible groups on retry. Reject extra, missing, active or altered committed state.
- Preserve every existing field except listed events.workspace_id, including event timestamps, scanner/user authority/credentials/platform state, guest/QR/manual/admission/offline identifiers and records. Use explicit query writes without hooks/defaults.
- Keep fixed safe errors and aggregate outcomes private, discard original query exceptions, and retain rollout_ready=false with actual mapping/grant/restore/engine gates unverified. Add no CLI/HTTP/job/listener/config activation.
- Verify failures at all write stages, silent mapping mutation, partial retries, bounded ID batches, raw generated-ID overflow and schema metadata cases; run focused/full regression and preservation checks, then update dated evidence.

Verification evidence:

- Full isolated regression passed **656 tests / 6,689 assertions** (101.487276s). Focused backfill/schema checks passed **75 tests / 1,290 assertions** (10.530528s). Five new PHP files passed Pint and syntax checks. All 97 HTTP routes, unchanged read-only command options and the 31-table empty-schema CLI smoke passed; source whitespace, zero application callers and baseline preservation checks passed.
- Added 42 backfill cases / 939 assertions and 33 schema cases / 351 assertions. Coverage includes first apply, order-independent exact retries, unused-name non-adoption, current owner rechecks, 11 committed-state drift cases, all four write-stage rollbacks, earlier-group conservation/retry, private query failures and 503-owner/505-event batches.
- Independent review closed a silent mapping-mutation postcondition gap before execution; tests require current-group rollback after deletion or retargeting. Review also rejected lossy partial/expression/prefix indexes and nonallocating SQLite keys. A raw-driver check prevents Laravel's generated-ID integer cast from clamping an oversized MySQL identity into an existing target; labelled local processor simulations cover plan/workspace rollback.
- Focused and full runs passed on their first execution. MySQL metadata, raw-ID and query-hook cases are simulations, not actual engine/locking evidence. All writes were confined to independent SQLite memory fixtures; no default/configured database was touched.
- Compared 320 baseline paths: all pre-existing application source/tests, .env, compiled assets, dependencies and unrelated files remain intact. Only task docs changed among existing files; three source and two test PHP files were added. No runtime application caller exists.

Files: LegacyWorkspaceBackfill, OwnershipBackfillSchema, OwnershipBackfillException and two feature test classes; [preparation guide](WORKSPACE_BACKFILL_PREPARATION.md), [phase evidence](PHASE_03_REPORT.md) and affected references.

Limits: Actual inventory, legitimate owner/platform authorization, database/file restoration, maintenance fencing and MySQL/MariaDB concurrency remain unverified. Coverage checks cannot fence unrelated event writers; final failure may retain earlier commits and an empty private plan. Distinct new proposals covering only new unowned work can be structurally eligible, but cannot adopt existing owned targets as a retry and still require explicit review. The service is dormant source preparation, not approval, a complete audit journal or actual rollout. Phase 3 stays IN PROGRESS.

## Task P3-006 — COMPLETE (2026-10-18)

Objective: prove bounded actual-engine migration/backfill behavior on a separate synthetic MySQL server and preserve all existing environments.

Acceptance criteria:

- Verify actual distribution/server identity, separate loopback-only TEMP instance, generated private credentials and database-scoped application account; preserve configured MySQL/XAMPP/other services and app configuration.
- Apply full repository DDL, inspect actual restrictive keys/constraints and exercise populated legacy conservation plus empty expansion/ledger reversal/reapply.
- Prove per-workspace rollback, partial and no-write exact retry, real unsigned-ID overflow refusal and selected independent-connection row-lock exclusion/release; keep rollout gates unverified.
- Verify strict UTF-8/UTC/FK settings, same-identity restart and synthetic SQL dump/restore conservation; stop only the owned process, prove safe unavailable behavior and remove temporary plaintext credentials.
- Repair any bounded discovered migration defect with actual-engine focused regression, full backend/format/CLI/routes and baseline checks; document limits without claiming customer rollout.

Verification evidence:

- Full isolated regression passed **656 tests / 6,689 assertions** (165.260393s), with zero errors/failures/skips. The repaired migration passed Pint/syntax. All 97 routes and the 31-table empty-schema read-only CLI smoke passed; baseline/link/whitespace checks passed.
- Actual MySQL 8.4.11 Community (Oracle-signed Win64 distribution) applied all 27 repository migrations on a fresh synthetic schema. The fixture passed 108 checks, conserved 39 legacy rows, rejected seven unique/FK violations and verified eight historical offline-index transitions. It proved current-group rollback/earlier-group retention, partial retry and zero-write completed retry, independent-connection row-lock timeout/release, exact-name drift rejection under a case-insensitive SQL collation, and real unsigned plan/workspace IDs above PHP_INT_MAX refusing without accidental target adoption.
- The same server identity and every fixture row survived restart. A scoped-account mysqldump/mysql restore into the second fresh owned schema matched all rows across 31 tables and passed the backfill schema guard. After clean shutdown, the connection failed safely with driver code 2002/exit 2; port 43484 was free and task-created plaintext profile/client/bootstrap credentials were removed.
- The first actual migration run failed with MySQL 1553 at 2026_07_23_190000: dropping the last old unique index removed the index supporting os_log_session_fk before its replacement existed. The narrow repair creates the four replacement indexes before dropping their predecessors in both directions; final names/columns/uniqueness are unchanged. Fresh history and empty down/up now pass with FK checks enabled. It does not repair an already partially applied live migration or certify populated rollback after duplicate conflict records exist.
- Compared 326 task-start paths; only the named historical migration and task docs changed among existing files. .env, app key, public build, dependency manifests/locks and all other captured source/tests are preserved. No runtime backfill caller exists. The temporary restore runner required explicit schema enumeration before the successful result; this was a fixture-only correction.

Files: historical offline-conflict migration index ordering; [actual-engine evidence](MYSQL_REHEARSAL.md), [phase report](PHASE_03_REPORT.md) and affected operator/canonical references.

Limits: This is bounded synthetic MySQL 8.4.11 evidence. Configured/customer database identity/schema/inventory, representative customer database and private-file restoration, legitimate owners/platform approval, maintenance fencing, comprehensive races/deadlocks and MariaDB remain unverified. No configured database, XAMPP, other project data/service, runtime writer, grant, frontend/dependency change or deployment occurred. Phase 3 remains IN PROGRESS.

## Task P3-007 — COMPLETE (2026-10-19)

Objective: inspect exact private backfill progress without invoking the writer, changing records or implying operational approval.

Acceptance criteria:

- Require explicit configured connection and bounded private local strict manifest; share existing parser/file policy and exact state validation with the dormant writer.
- Observe unstarted, retained-plan, partial and exact states with aggregate counts only, including empty proposals and reordered identities; validate current owner eligibility, exact names/inactive members/event sets and full unowned coverage.
- Reject drift, changed/unknown mappings, platform intent, unsafe schema/enforcement/ambient transactions and unavailable queries with fixed safe output and documented exit codes.
- Perform no apply call, transaction, lock, write, repair, default-context selection, activation, grant or approval; preserve all schema/rows/timestamps/default connection/private file/config/build/dependency values.
- Preserve old preflight and writer contracts through focused/full regression, syntax/format/routes/CLI and baseline checks; keep all external gates unverified.

Verification evidence:

- Full isolated regression passed **717 tests / 10,483 assertions** (111.554235s), with zero errors/failures/skips. Focused inspection/writer/schema/proposal checks passed **169 tests / 5,677 assertions** (12.416401s). Eight scoped PHP files passed Pint/syntax; all 97 routes, new command help/missing-connection exit2 and the 31-table empty-schema CLI smoke passed. Baseline/whitespace/local-link checks passed.
- Added **33 inspection cases / 2,938 assertions** and **28 command/reader cases / 856 assertions**. Includes all progress states, exact reordered observation,11 drift cases,503-owner/505-event batches, safe failures, no writer calls and all fixed exit reasons.
- SQL/transaction-event observation and full database snapshots prove read-only behavior on independent SQLite clones; source review confirms every inspection shared-read call uses its non-locking path. The old writer/schema/proposal focused regressions pass with unchanged write/retry/raw-ID outcomes.
- Compared327 task-start paths: only two existing PHP files changed through narrow shared state/file-reader extraction; four source and two test PHP files were added. .env/app key, public build, dependency manifests/locks, all migrations and unrelated source/tests match baseline. No runtime writer caller exists; independent review found no blocker.

Files: BackfillInspect, LegacyWorkspaceBackfillInspection, OwnershipBackfillState, OwnershipManifestFile, two narrow existing-service/command refactors and two feature test classes; [operator guide](BACKFILL_PROGRESS_INSPECTION.md), [phase report](PHASE_03_REPORT.md) and affected references.

Limits: Results are multi-query observations, not a consistent snapshot, retry guarantee, legitimate-owner approval, backup evidence or maintenance fence. All four external gates stay unverified and rollout_ready=false. No configured/customer database, actual owner/platform grant, runtime writer, HTTP/API/UI/dependency/Android change or deployment was involved. Phase 3 remains IN PROGRESS.

## Task P3-008 — COMPLETE (2026-10-19)

Objective: prepare fresh independent account/platform/workspace-membership facts without changing existing authorization or granting action permissions.

Acceptance criteria:

- Require explicit connection and strict positive PHP integer user/optional workspace identifiers; inspect only safe three-table prerequisites and bounded primary reads, without model/session/default/ledger/name inference.
- Keep canonical active-account eligibility, explicit platform capability and exact requested active owner/manager membership independent; reject missing/inactive/malformed/duplicate/cross-workspace state without platform bypass or Manager action grants.
- Reread visible persisted state each invocation; preserve caller transaction visibility/level and validate revocation without a cache.
- Return private immutable facts only, with no IDs/PII/JSON fields/string conversion/action methods. Sanitize fixed errors without original query data, logs or previous context.
- Introduce no runtime caller, write, transaction, lock, grant, activation, route/policy/model hook, schema/API/UI/dependency/Android change; verify focused/full regression, formatting, smoke and baseline conservation.

Verification evidence:

- Full isolated regression passed **795 tests / 12,771 assertions** (127.333681s), with zero errors/failures/skips. Focused authority/authentication/workspace checks passed **128 tests / 2,549 assertions** (9.153834s). Five new PHP files passed Pint/syntax; all 97 routes, unchanged read-only CLI options/exits and 31-table empty-schema inventory smoke passed. Baseline/whitespace/local-link checks passed.
- Added **70 resolver cases / 2,259 assertions** and **8 immutable-context/exception cases / 29 assertions**. Includes strict raw booleans/IDs, independent platform/member boundaries, fresh revocation, duplicates/orphans,10 unsafe schemas, named prefixes, stale/empty read PDOs, LIMIT2 bindings and caller-owned transaction conservation.
- Full schema/all-row snapshots and SQL/transaction observation prove read-only behavior; original query failures are sanitized. No runtime caller exists. All 334 pre-existing baseline paths remain unchanged outside task docs; three source and two test PHP files were added.
- The first focused run encountered one test-helper cleanup error for a null dispatcher on the unsupported-driver fixture. The corrected helper unsets it; application source needed no change. A subsequent review/trap regression reproduced an auxiliary SQLite replica metadata read; the new resolver was corrected to process required SQLite metadata on the primary only. Corrected trap/focused/full runs passed.

Files: AuthorityContextResolver, AuthorityContext, AuthorityContextException and two test classes; [scope and evidence](AUTHORITY_CONTEXT.md), [phase report](PHASE_03_REPORT.md) and affected role/security/canonical references.

Limits: Observed facts are not authorization or an unforgeable token. Current admin/scanner account-role compatibility is preserved; Manager action permissions, platform support bypass, ownership transfer/last-owner rules and runtime tenant enforcement remain undefined. Visibility follows the explicit connection and caller transaction/isolation; fresh queries do not fence concurrent changes or invalidate an already retained context. Real ownership/platform approval, customer restore, membership activation, maintenance fencing and Phase4 action/resource policies remain open. Phase3 remains IN PROGRESS.

## Task P3-009 — COMPLETE (2026-10-19)

Objective: prove selected actual service contention and worker-disconnect recovery without changing application behavior or existing environments.

Acceptance criteria:

- Use a new identity-verified, private loopback MySQL8.4.11 server, scoped generated credentials, synthetic schemas and separate owned PHP processes; preserve XAMPP/configured DBs/app configuration/other projects.
- Control existing-plan and missing-plan races and competing single-group proposals with bounded barriers; observe actual engine lock waits and permit documented sanitized conflicts without introducing automatic retries.
- Terminate only an identity-verified owned worker during group2, prove unfinished-group rollback and earlier-group conservation, then verify partial inspection, remaining-group resume and no-write exact retry.
- Conserve every legacy field except permitted event ownership, avoid duplicate/adopted/reassigned committed targets, preserve private outcomes and all external readiness gates.
- Stop all owned processes/port, remove generated plaintext credentials and verify CLI/source/harness/link evidence; reuse passing source regression only when baseline proves application unchanged.

Verification evidence:

- All four bounded actual MySQL8.4.11 cases passed with 11 separate PHP workers across four fresh synthetic schemas, each applying27 repository migrations/31 tables. Actual owned-thread lock waits, commit/retry outcomes and full legacy-row conservation were verified. Five TEMP PHP files passed syntax checks and three PowerShell scripts parsed successfully. All 97 routes, unchanged read-only CLI options/exits and 31-table empty-schema inventory smoke passed; baseline/links passed.
- Existing-plan workers produced one created workspace and a waiting verifier with zero DML. The fresh missing-plan race produced one successful creator and one sanitized engine conflict; exact retry wrote nothing and no duplicate target committed. Competing single-group proposals produced one exact winner and an observed MySQL1213 deadlock sanitized as database_unavailable; the losing retry refused ledger_conflict with zero DML, reassignment or name adoption, retaining one empty losing plan. Terminating only the verified owned PHP worker during group2 caused InnoDB to discard the entire unfinished group while preserving group1; inspector partial, remaining-group resume and zero-DML exact retry all passed.
- The separate loopback127.0.0.1:43489 server used a fresh private TEMP data/log/tmp/PID directory, --no-defaults, MySQLX disabled and no Windows service. Oracle signature/version and unchanged distribution archive hash were verified. Generated application credentials were scoped to four exact fixture schemas; unscoped CREATE DATABASE was rejected. UUID/datadir/bind/port and strict SQL/utf8mb4/UTC/FK settings were checked before fixture writes. All owned workers and the server stopped cleanly; port43489 was free, unavailable connection returned safe2002 and all four generated plaintext credential files were removed.
- No application code, migrations or tests changed in P3-009. The current P3-008 full result **795 tests / 12,771 assertions** (127.333681s, zero errors/failures/skips) was reused only after all 340 captured pre-existing source/config/build/dependency paths were checked unchanged outside task docs. No new full-suite run is claimed for this TEMP-only rehearsal.
- Harness-only corrections addressed early worker registration/readiness and an overly narrow expected conflict reason. The application needed no repair; the successful run retained safe deadlock behavior and existing retry semantics.

Files: TEMP-only server/coordinator/worker/fixture/identity-safe cleanup artifacts; [bounded concurrency evidence](MYSQL_CONCURRENCY_REHEARSAL.md), [phase report](PHASE_03_REPORT.md) and affected operator references.

Limits: This verifies selected service interleavings and a worker disconnect on the recorded synthetic MySQL build, not complete crash/durability/deadlock/race coverage, another hosting engine, maintenance fencing, customer backup/file restoration or legitimate owner/platform approval. Competing multi-group proposals may retain different committed groups; no single complete winning proposal is guaranteed in general. No automatic retry/global mutex, runtime writer, platform grant, membership activation, XAMPP/configured database change or deployment occurred. Phase3 remains IN PROGRESS.

## Task P3-010 — COMPLETE (2026-10-19)

Objective: verify the existing scanner admission capacity and history baseline on actual isolated MySQL before further migration, preserving current application behavior.

Acceptance criteria:

- Use a fresh identity-verified private loopback MySQL server, scoped generated credentials, synthetic repository schemas and independent authenticated scanner test workers.
- Exercise QR/QR, manual/manual and both mixed last-entry pairs through the unchanged Laravel HTTP kernel; observe actual lock waits and precommit invisibility, exactly one accepted admission and no capacity overshoot.
- Conserve historical/immutable rows and exact counter/Admission/Checkin quantities, including existing QR/manual rejection-audit differences.
- Interrupt only a verified owned holder after counter DML; prove full uncommitted rollback and a subsequent single admission.
- Preserve source/config/build/dependencies and existing databases/services, verify route/CLI/harness/source evidence, stop owned processes and remove generated plaintext credentials.

Verification evidence:

- Five selected cases passed on a separate MySQL 8.4.11 server using ten independent PHP workers and five fresh synthetic databases, each with 27 repository migrations and 31 tables. Four last-entry pairs (QR/QR, manual/manual, QR/manual and manual/QR) showed actual owned-thread lock waits and precommit invisibility. Each pair committed exactly one admission, rejected the other attempt and ended at used_entries=3, allowed_entries=3, with no overshoot.
- The guest counter increase, new Admission quantity and successful Checkin entries all equalled one. Prior historical rows were preserved exactly. QR rejection added a zero-entry Checkin and a verify ScanLog; manual rejection added neither, preserving existing response/audit differences. Every other table/record and immutable guest, manual-code, QR, account and offline-provenance field matched the fixture snapshot.
- Terminating only the identity-verified owned PHP holder after its uncommitted guest-counter UPDATE caused MySQL to roll back the counter/status change and leave history unchanged. Its connection thread disappeared, the complete prior committed snapshot returned, and a fresh worker then admitted exactly once.
- No application code, migration or test changed. All 341 captured pre-existing paths were compared; changes were limited to task documentation. The unchanged-source P3-008 gate of **795 tests / 12,771 assertions** (127.333681s, no errors/failures/skips) was reused; no new full-suite run is claimed. Five TEMP PHP files passed syntax checks, four PowerShell scripts parsed, all 97 routes registered, and existing read-only command options/exits and 31-table empty-schema inventory smoke passed.
- The private server bound only to 127.0.0.1:43490 with MySQLX disabled and no Windows service. Its UUID, data directory, version and session strict SQL/utf8mb4/UTC/FK settings were checked before fixture writes. Oracle Authenticode signature and the existing distribution archive SHA-256 were verified. Generated application credentials had grants only on the five exact fixture databases; unscoped CREATE DATABASE was rejected. All owned workers/server stopped, the port was free, unavailable connection returned safe 2002, and four generated plaintext credential files were removed.

Files: TEMP-only server/fixture/coordinator/worker/cleanup artifacts; [admission evidence](MYSQL_ADMISSION_REHEARSAL.md), [phase report](PHASE_03_REPORT.md) and affected scanner/database/test/operator references.

Limits: These are selected existing-route transaction cases on synthetic data, not exhaustive concurrency, hosted-engine, throughput, browser/camera/device, offline interoperability or customer tenant-isolation proof. Online admission still has no request idempotency key: retrying a lost successful response can consume more allowance while capacity remains. Existing event-row serialization is unchanged. Real ownership/platform approval, customer database/private-file restore, maintenance fencing and runtime tenant policies remain open; Phase 3 remains IN PROGRESS.

## Task P3-011 — COMPLETE (2026-10-19)

Objective: verify selected dormant authority-reader primary routing, committed revocation and caller-transaction visibility on isolated actual MySQL without enabling authorization.

Acceptance criteria:

- Use a fresh private identity-verified MySQL server, two exact synthetic fixture databases, scoped generated credentials and independent reader/writer connections.
- Verify separate account/platform/member facts, same-reader committed changes, primary-only metadata/row reads, strict malformed/duplicate/unsafe-ID handling and private safe outcomes.
- Characterize caller-owned REPEATABLE READ and READ COMMITTED without resolver locks or transaction changes; retain all documented visibility limits.
- Conserve all fixture rows/schema around every read, restore deliberate test mutations, and preserve existing application/config/build/dependency files.
- Verify harness/HTTP registration/CLI/source evidence, stop only the owned server and remove generated plaintext credentials.

Verification evidence:

- All 34 selected actual MySQL 8.4.11 resolver observations passed using one retained resolver, a primary connection, a separate committed writer and a diagnostic shadow connection. The primary fixture applied 27 repository migrations/31 tables; the shadow fixture used only the three required tables. Native role/platform/membership separation, missing context, committed flag/member/account changes, duplicate/malformed membership state, oversized unsigned membership ID, removed parent/account and strict input/privacy checks passed.
- A divergent actual shadow read PDO did not supply authority. A throwing read-PDO closure remained unopened across row and metadata reads (zero opens). The explicit reader worked with an unavailable configured default and preserved that default. Snapshots of all rows and SHOW CREATE TABLE output across both fixtures were identical immediately around every resolver invocation; captured SQL/transaction events showed no resolver writes, locks or transaction control.
- Under caller-owned REPEATABLE READ, an established snapshot retained earlier platform/member facts after an independent writer committed revocation. The writer committed while the reader transaction stayed open, without waiting on resolver record locks. After caller rollback, the same resolver/connection observed the revocation. Under READ COMMITTED, the next resolve inside the same caller transaction observed committed account deactivation. Both cases preserved caller transaction level and PDO state. Every deliberate fixture mutation was then restored to the complete original row/schema snapshot.
- Application source, migrations and tests were unchanged across all 342 captured baseline paths outside task docs. The current P3-008 full result **795 tests / 12,771 assertions** (127.333681s, zero errors/failures/skips) was reused after preservation checks; no new full-suite run is claimed. Four TEMP PHP files passed syntax checks, three PowerShell scripts parsed, all 97 routes and existing read-only command options/exits and 31-table empty-schema inventory smoke passed. Independent read-only harness review found no blocking issue; no application repair was required.
- The new private task server bound to 127.0.0.1:43491, disabled MySQLX and registered no Windows service. UUID/datadir/version and primary strict SQL/utf8mb4/UTC/FK settings were checked before synthetic writes; independent connections used the same verified UUID and exact fixture database. The generated application account was scoped to two exact databases and unscoped CREATE DATABASE was refused. The previously verified Oracle distribution archive hash remained unchanged. The owned server stopped cleanly, the port was free, unavailable connection returned safe 2002 and all four generated plaintext credential files were removed.

Files: TEMP-only isolated server/harness/fixture/lifecycle artifacts; [actual authority evidence](MYSQL_AUTHORITY_REHEARSAL.md), [resolver contract](AUTHORITY_CONTEXT.md), [phase report](PHASE_03_REPORT.md) and affected operator references.

Limits: This is selected synthetic MySQL visibility evidence, not runtime authorization, an action permission or a revocation fence. An old retained context remains old, and caller transaction isolation controls visible rows. Different queries in a resolver invocation are not a global atomic authority snapshot. Manager action policy, platform support bypass, ownership transfer/last-owner rules and runtime tenant enforcement remain undefined. Real owner/platform approval, customer database/private-file restore, membership activation and maintenance fencing remain open. Phase 3 remains IN PROGRESS.

## Task P3-012 — COMPLETE (2026-10-19)

Objective: make project status understandable and align current authentication/API/workspace/database claims with verified source while retaining dated history.

Acceptance criteria:

- Check material current-state statements against source and preserved route/test evidence; distinguish historical audit results from the latest source gate.
- Correct stale API, token-assignment/logout, workspace and engine-verification statements without claiming customer isolation, Android scanner endpoints or deployed readiness.
- Provide a concise linked plain-language status page explaining ownership, platform staff, successful restore and controlled change-window requirements.
- Validate documentation references/whitespace and route registry equality while preserving all captured application/config/build/dependency files and existing data/services.

Verification evidence:

- Added a linked plain-language status page explaining existing features, dormant workspace preparation, selected test evidence and the real owner/platform/restore/change-window facts needed before real-data changes. Corrected current scanner API registration, precise scanner token login/me versus logout conditions, dormant ownership versus runtime policies, historical 29/current 31 table counts and configured-database versus synthetic-engine evidence. Historical audit dates/results remain identified.
- All 343 captured pre-existing paths were compared; only documentation changed. All 97 documented route method/path combinations match the preserved runtime snapshot exactly. Documentation links and whitespace passed; independent read-only review found no blocking overclaim. The unchanged-source 795-test / 12,771-assertion gate was retained. No backend test/build/dependency run or application/database/service/grant change was needed for this documentation-only task.

Files: [project status](PROJECT_STATUS.md), current canonical/scanner/authentication/security/tenancy/test/route/operator references, this plan and the phase/report notes.

Limits: documentation establishes no real owner, platform approval, target hosting, customer database/private-file restoration or maintenance fence. Phase 3 remains IN PROGRESS.

## Task P3-013 — COMPLETE (2026-10-20)

Objective: harden the existing administrator CSV downloads against formula-looking user fields while preserving current access/report behavior and stored records.

Acceptance criteria:

- Reproduce unsafe CSV fields through all five existing HTTP downloads before repairing their shared output boundary.
- Prefix risky spreadsheet-facing string cells and use standard CSV quoting; test control/ASCII/fullwidth/whitespace cases and quote/comma/backslash/newline round-trip.
- Preserve normal text, headers/columns/counters, filenames/MIME, selected-event filtering and browser authorization; prove repeated export and whole-database row conservation, including manual code and QR token.
- Pass focused/full regression, scoped formatting/syntax, unchanged route/CLI/source/link checks; document the raw TAB prefix and remaining spreadsheet-client limits.

Verification evidence:

- The pre-fix HTTP regression reproduced unguarded formula cells in all five CSV downloads (5 failures / 35 assertions). After repair, focused report/CSV/checkin/selected-event/XLSX checks passed **61 tests / 569 assertions**. The complete isolated backend suite passed **828 tests / 13,004 assertions** (121.874075s), with no errors/failures/skips. Added 33 HTTP cases covering five routes, 23 cell payloads, repeatability, whole-database row conservation and existing browser authorization.
- Independent read-only review found no blocking code issue. Its suggested ordinary leading-TAB case was added and passed; client-limit wording was tightened to the actual C0 byte range. The initial full run passed 827 tests / 12,999 assertions before that test-only addition; the final result below includes all 33 new cases.
- All checkin/report CSV output now uses one private writer. Formula-looking ASCII/fullwidth strings, formula prefixes after whitespace/control characters and leading C0 control bytes (0x00–0x1F) receive a TAB prefix in the exported quoted cell. This includes international +phone values. Ordinary Unicode/text, numeric counters, headers, column order, selection/filtering, filenames and MIME remain compatible. An explicit empty fputcsv escape preserves standard quote/comma/backslash/newline round-trip; stored guest/QR/manual-code/history values do not change. Typed XLSX export is unchanged.
- Compared all 344 pre-existing baseline paths and checked new additions. Only the existing controller CSV call sites/new helper, one new test file and task docs changed. Removing the helper and reversing four call substitutions reconstructs the original controller exactly after line-ending normalization. New-test Pint and both PHP syntax checks passed; the inserted method matches its Pint-formatted fragment while unrelated pre-existing controller formatting was preserved. Runtime remains 97 routes, with exact documented method/path agreement; existing read-only CLI options/exits and 31-table inventory smoke passed. No configured database, service, .env/app key, build, dependency, tenant policy, UI or Android change occurred.

Files: [AdminController](../app/Http/Controllers/AdminController.php), [CSV regression tests](../tests/Feature/CsvExportSafetyTest.php), [CSV format and limits](CSV_EXPORTS.md), this plan and affected current/security/test/phase references.

Limits: CSV has no native cell type. The TAB remains part of raw exported data, so programmatic consumers must account for this documented report format. Actual Excel/LibreOffice rendering and edit/save/reopen behavior were not verified; no universal spreadsheet/parser guarantee is claimed. This repair does not enable customer tenancy or close the real ownership/platform/restore/fencing gates. Phase 3 remains IN PROGRESS.

## Task P3-014 — COMPLETE (2026-10-20)

Objective: refresh configured-connection availability evidence using one intended harmless SELECT without customer inventory, data changes or service startup.

Acceptance criteria:

- Use the existing explicit Laravel mysql settings privately, preserve DB_* / DB_URL / .env / app key, check cached-config presence and bound connection timeout.
- Attempt only SELECT 1 with normal connector session setup; do not inspect customer tables/owners or run inventory without verified read-restricted credentials.
- Record fixed safe availability/unavailability and exit behavior without private context; do not equate a completed diagnostic with rollout readiness.
- Preserve all captured source/config/build/dependency files, retain the prior source gate only when unchanged, and validate dated documentation references/whitespace.

Verification evidence:

- The 2026-10-20 fixed SELECT-only configured mysql diagnostic returned sanitized database_unavailable / driver 2002 and exit 2 during connection setup. No connection succeeded and zero application SELECT statements completed. Cached application config was absent; existing DB_* / DB_URL settings and credentials were preserved. Normal connector initialization would be session-only if a connection succeeded. No customer inventory or target/account/schema/owner validation was performed.
- The TEMP PHP diagnostic passed syntax checks and emitted only a fixed safe status; no host/database/user/password/DSN, raw exception, SQL trace or customer data was printed. All 346 captured pre-existing paths remain unchanged outside task docs, including application source/tests, .env/app key, builds and dependencies. The existing 828-test / 13,004-assertion source gate was retained after preservation checks; no new backend suite is claimed for this TEMP/docs-only diagnostic. Local links/whitespace passed. No migration, seed, repair, grant or service startup/shutdown command was issued.

Files: TEMP-only probe/log/safe-result/preservation artifacts in codex-harusi-p3014-20261020; this plan, phase report and existing operator/status references.

Limits: Completing this bounded diagnostic does not make the connection available or complete Phase 3. Actual customer inventory still requires an established read-restricted account and intended target; legitimate owners/platform staff, database/private-file restoration and a controlled change window remain unverified.

## Task P3-015 — COMPLETE (2026-10-20)

Objective: stop the default demo seeder from deleting existing data or creating known-password samples outside exact local/testing environments.

Acceptance criteria:

- Deny disallowed environments as the first operation, including direct calls and forced Artisan execution, before database/transaction/hash work.
- Remove deletion and foreign-key toggles; refuse each populated sample-data target using primary reads inside one transaction.
- Preserve allowed fresh sample fields/counts, refuse repeat runs without data changes, and prove full rollback/retry after an injected insert failure.
- Pass focused/full regression, scoped formatting/syntax, unchanged route/CLI/source and documentation checks; document remaining recovery/account/environment/concurrency limits.

Verification evidence:

- The pre-fix direct-production test reproduced unsafe work on an empty isolated SQLite fixture: 1 test / 7 assertions failed, with 89 SQL statements attempted. After repair, focused checks passed **41 tests / 678 assertions** and the complete backend suite passed **849 tests / 13,574 assertions** (132.606666s), with zero errors/failures/skips. All 21 new seeder cases passed in both runs: exact environment/direct/forced refusal before SQL/transaction/hash work, each populated target, allowed sample compatibility, repeat refusal and injected third-QR failure with full row rollback including the observer-created event, followed by successful retry.
- Scoped Pint and both PHP syntax checks passed. The original sample insert body is preserved exactly after removing one indentation level; destructive resets and FK toggles are absent. All 350 captured pre-existing paths were compared; only the default seeder, root README and task docs changed, with one new test file. Existing application/model/schema/routes/scripts, .env/app key, builds and dependencies were preserved. Runtime remains 97 routes with exact documented method/path agreement; read-only CLI options/exits and the 31-table empty-schema inventory smoke passed. Local documentation links and whitespace passed. No configured database migration/seeding, real account change, external message, service, frontend, Android or dependency change occurred.

Files: [default seeder](../database/seeders/DatabaseSeeder.php), [safety tests](../tests/Feature/DatabaseSeederSafetyTest.php), [setup](../README.md), [demo seeding scope](DEMO_SEEDING.md), this plan and affected phase/security/test references.

Limits: The environment label is trusted configuration, not verified database identity; six empty target tables do not mean the whole database is empty. This establishes one-invocation transaction behavior on SQLite, not concurrent seeding, a global maintenance fence or hosted-engine proof. Existing demo accounts, test factories, private backup artifacts and the separate scripts/recover-wedding-data.php --apply writer remain outside the repair. R09 remains partly addressed. Real ownership/platform approval, customer database/private-file restore and maintenance fencing remain open; Phase 3 remains IN PROGRESS.

## Task P3-016 — COMPLETE (2026-10-20)

Objective: verify selected default-seeder refusal/conservation/rollback cases on actual InnoDB using invented data and fresh task-owned schemas.

Acceptance criteria:

- Verify signed MySQL distribution, exact owned server/schema identity, strict settings and restricted generated credentials before synthetic writes.
- Prove outside-environment refusal before work, six populated-target refusals, allowed original samples and repeat conservation.
- Inject a third-QR failure, compare all rows/DDL and transaction/FK state after rollback, then retry successfully with only permitted allocator gaps.
- Stop only the verified owned server, prove unavailable/port-free behavior, remove generated plaintext credentials, and validate source/route/CLI/syntax/documentation evidence.

Verification evidence:

- All **16 selected actual MySQL 8.4.11 cases** passed (1774 harness assertions) across two fresh task-owned schemas, each with 27 repository migrations and 31 InnoDB tables. Six production/staging/direct/forced calls across empty and populated states refused before SQL, transaction or hash work. Each of the six populated sample targets refused with complete row/DDL conservation and no seeder DML; an allowed local run preserved the original samples, and repeat refusal preserved all 31 tables.
- An injected failure before the third QR insert occurred after positive partial counts in users, guests, QR codes, all three history tables and the observer-created default event. InnoDB rolled back every fixture row, including that event, to the complete pre-call 31-table snapshot; Laravel/PDO transaction state returned to idle and foreign-key enforcement stayed enabled. The same testing path then succeeded after the fault callback was disarmed. SHOW CREATE TABLE definitions matched with only the AUTO_INCREMENT option excluded: sequence gaps after rollback are permitted and no sequence restoration is claimed. Unrelated tables and the separate fresh schema remained unchanged.
- The previously identified Oracle-signed distribution retained its expected archive SHA-256 and version. A new private TEMP datadir/log/tmp/PID/profile bound only to 127.0.0.1:43492, with MySQLX disabled and no Windows service. UUID/datadir/version and strict SQL/utf8mb4/UTC/FK settings were checked before fixture writes. Generated application credentials were scoped to the two exact databases; unscoped CREATE DATABASE was refused. Only the verified owned server was stopped; its port was free, a fresh connection returned safe driver 2002, and all four generated plaintext credential files were removed. No external messages were sent.
- All 352 pre-existing baseline paths were compared and new additions checked; application source/tests/models/migrations/scripts, root README, .env/app key, builds and dependencies were preserved. The existing P3-015 **849-test / 13,574-assertion** full gate was reused only after unchanged-source comparison; no new full-suite run is claimed. Three TEMP PHP files passed syntax checks and three PowerShell scripts parsed. All 97 documented route method/path pairs matched runtime registration; read-only CLI options/exits, 31-table empty-schema inventory, documentation links and whitespace passed. Independent read-only harness review found no remaining blocking issue before execution.

Files: TEMP-only helpers/results in codex-harusi-p3016-20261020; [rehearsal scope](MYSQL_DEMO_SEEDING_REHEARSAL.md), this plan and phase/operator references. No application source change.

Limits: This verifies selected single-process seeder cases on the recorded synthetic MySQL build. It does not prove concurrent seeding, a global maintenance fence, power-loss durability, another hosting engine, database identity from APP_ENV, real administrator provisioning or credential revocation. Existing known-password accounts, separate recovery --apply, factories and private backup handling remain outside this repair. No configured/customer database, XAMPP/other project service, ownership/grant, frontend/Android/dependency change or deployment occurred. Real ownership/platform approval, customer database/private-file restoration and maintenance fencing remain open; Phase 3 remains IN PROGRESS.

## Task P3-017 — COMPLETE (2026-10-20)

Objective: exclude the existing root portable-backups directory from ordinary Git discovery while preserving backup data and the repository index.

Acceptance criteria:

- Append only the exact root backup-directory rule and preserve all existing ignore bytes.
- Reproduce the original gap in an isolated Git fixture; verify each positive and negative invented path in both the fixture and actual repository.
- Preserve index hash/length/mtime, verify tracked-backup count privately, and avoid backup-content traversal or Git mutations.
- Compare source/config/build/dependency baseline, retain existing regression evidence only after preservation, and update affected documentation/limits.

Verification evidence:

- The copied original ignore rules left all four invented backup paths unignored in a TEMP-only Git fixture. After appending the exact root /portable-backups/ rule, all **11 per-path probes passed in both the temporary fixture and actual repository**: root directory/nested SQL/ZIP/binary probes matched that exact .gitignore rule, while similar names, nested directories elsewhere and ordinary source/setup paths remained unmatched. No persistent probe files were created in Harusi.
- All 359 captured pre-existing paths were compared. Only .gitignore and affected docs changed; the original ignore bytes remain an exact prefix with only one appended directory rule. The resolved repository index retained its SHA-256, byte length and modification time; indexed portable-backup path count was zero before and after. Commands used read-only Git operations with optional locks disabled; no add/rm/update-index/commit/history operation occurred. Backup contents were not traversed, opened, printed, copied, deleted or renamed; no content-level backup hash or restoration is claimed.
- Application source/tests/models/migrations/scripts, public/index.php, root README, .env/app key, builds and dependency files were preserved. The existing P3-015 849-test / 13,574-assertion gate and P3-016 97-route/31-table CLI evidence were retained only after source comparisons; no unnecessary new backend suite, build or CLI run is claimed. Local links and whitespace passed. No configured database, service, account, ownership/grant, API/UI/Android/dependency change or deployment occurred.

Files: [root ignore rules](../.gitignore), [backup/recovery](BACKUP_AND_ROLLBACK.md), this plan and phase/security/risk/test/deployment/status references; TEMP-only verification artifacts in codex-harusi-p3017-20261020.

Limits: The rule reduces accidental inclusion during ordinary Git discovery/add; forced add can bypass it. It does not remove already tracked/history content, revoke/rotate credentials, encrypt/protect stored files, validate backups/restore or exclude backups from arbitrary packaging/deployment. It covers only the root portable-backups directory, not renamed/copied/nested backups elsewhere. Existing demo accounts and the separate recovery --apply writer remain outside this task; R09 stays partly addressed. Real ownership/platform approval, customer database/private-file restore and maintenance fencing remain open; Phase 3 remains IN PROGRESS.

## Task P3-018 — COMPLETE (2026-10-21)

Objective: verify existing user/reset-token locking and credential/token conservation under selected independent-worker reset/device-login orderings and one worker disconnect.

Acceptance criteria:

- Verify isolated signed MySQL distribution/server/schema/settings/grants before synthetic writes; preserve all configured data/services and source.
- Exercise reset/reset and reset/device-login in both orders using completed-write barriers, actual owned-thread record lock waits and precommit snapshot comparisons.
- Verify exact HTTP/DML outcomes, new/old password behavior, remember rotation, target reset/device-token consumption and whole-row/immutable/schema conservation.
- Terminate only an identity-verified owned reset holder, prove full rollback and original-token retry, then stop all owned processes, verify unavailable/port-free behavior and remove generated plaintext credentials.
- Pass TEMP syntax, unchanged-source regression retention, route/CLI and documentation checks; keep testing/hosting/event-effect limits explicit.

Verification evidence:

- All **four selected actual MySQL 8.4.11 cases / eight independent PHP HTTP-kernel workers** passed across four fresh synthetic schemas (27 repository migrations / 31 tables each). Three pairs observed actual owned-thread users RECORD lock waits and complete precommit row invisibility. Reset/reset ended at 302 success / 422 generic replay refusal; reset-before-old-password login ended at 302 / 401; old-password login-before-reset ended at 201 / 302, with its newly issued token then removed by the reset. Accepted calls executed exactly three DML statements; refused contenders executed zero.
- Each completed reset replaced only the target account password/remember token/updated_at, accepted the new password and rejected the old, consumed the target reset row and removed all target device tokens. The unrelated account, its reset/device rows and every other row across all 31 tables matched the baseline exactly, including guest manual code, QR token, counters and history. SHOW CREATE definitions matched with allocator counter values excluded; no AUTO_INCREMENT restoration is claimed.
- Terminating only the identity-verified owned reset worker after its completed uncommitted password/remember UPDATE and before token deletion caused complete database row rollback. Its MySQL thread disappeared and the full prior snapshot was restored, including password/remember/reset/device rows; the original reset token then succeeded on a fresh retry worker. Bounded polling accounted for rollback visibility after thread teardown.
- The existing Oracle signature/version and distribution archive SHA-256 were verified. A new private TEMP datadir/log/tmp/PID/profile server bound only to 127.0.0.1:43493, disabled MySQLX and registered no Windows service. UUID/datadir/version and strict SQL/utf8mb4/UTC/FK settings were checked before synthetic writes. Generated application grants covered only four exact fixture databases; unscoped CREATE DATABASE was refused. Owned workers/server stopped, independent process/port checks passed, a fresh connection returned safe 2002, and four generated plaintext credential files were removed. No passwords, reset/bearer tokens, raw rows or private connection context were printed in results; messaging fakes observed no outbound messages.
- All 359 captured pre-existing paths were compared and additions checked; application source/tests/models/migrations/scripts, root README/ignore rules, public/index.php, .env/app key, builds and dependency files were preserved. The existing P3-015 **849-test / 13,574-assertion** source gate was reused after unchanged-source checks; no new full-suite run is claimed. Four TEMP PHP files passed syntax checks and four PowerShell scripts parsed; independent read-only helper review found no blocking issue. Runtime/documented registration matched all 97 method/path pairs; existing read-only CLI options/exits and 31-table inventory smoke passed. Documentation links and whitespace passed.

Files: TEMP-only helpers/results in codex-harusi-p3018-20261020; [rehearsal scope](MYSQL_RECOVERY_REHEARSAL.md), this plan and affected phase/authentication/operator references. No application source change.

Limits: These are selected synthetic transaction schedules on the recorded MySQL build, not exhaustive concurrency, power-loss durability or another hosting environment. HTTP kernel calls used APP_ENV=testing (CSRF bypass) and array sessions/cache, so deployed browser/session/rate-limit behavior and SMTP/device delivery remain unverified. PasswordReset events dispatch synchronously inside the transaction: database rollback cannot undo arbitrary external listener effects; no such app listener was found and no external-effect guarantee is claimed. A token issued by an earlier valid login can subsequently be revoked; already-running requests are not retroactively cancelled. No configured/customer database, XAMPP/other service, account/ownership/grant, API/UI/Android/dependency change or deployment occurred. Real ownership/platform approval, customer database/private-file restoration and maintenance fencing remain open; Phase 3 remains IN PROGRESS.

## Task P3-019 — COMPLETE (2026-10-21)

Objective: refuse the existing recovery --apply writer outside exact local/testing environments before path/input parsing or database/hash work, preserving current dry-run and allowed-label behavior.

Acceptance criteria:

- Guard the apply flag immediately after bootstrapped option detection using exact local/testing labels, fixed stderr and exit 2 before input paths/CSV/QR processing.
- Safely reproduce the missing refusal on a TEMP script copy; verify denied/allowed/read-only labels, zero database/hash work, valid invented dry runs and input conservation.
- Preserve the entire original writer/parser/output body beyond the guard/flag move; do not run any real or successful recovery apply.
- Pass focused/full regression, scoped formatting/syntax, route/CLI/source/index and documentation checks; record trusted-environment, legacy-writer and real-recovery limits.

Verification evidence:

- The corrected pre-fix production-apply CLI regression failed solely because the original script reached usage exit 1 instead of fixed environment-refusal exit 2 (1 test / 16 assertions / 1 failure, 0.749364s). Its real Laravel bootstrap completed with zero connection/query/transaction/connector/hash work. Early TEMP harness failures were corrected before this acceptance reproduction: Windows cache paths are explicitly recognized and isolated, and incomplete-input probes use an explicitly nonexistent base CSV instead of relying on realpath of an empty string.
- After the narrow guard, focused seeder/recovery safety checks passed **42 tests / 972 assertions** (24.298359s). The complete isolated backend suite passed **870 tests / 13,976 assertions** (252.814126s), with zero errors/failures/skips. All 21 new CLI cases passed: seven disallowed environment labels plus exact local/testing across apply/read-only calls with an explicitly nonexistent base CSV, valid invented production/local dry runs and production apply with the same invented inputs.
- Each new case executes a hash-exact TEMP copy of the actual script through the real Laravel bootstrap with a separate absent environment/config cache, verified TEMP cache/storage paths and a synthetic key. All five built-in database connectors are replaced by throwing spies; shutdown evidence requires one completed bootstrap and zero DB connection/query/transaction/connector/hash calls. Invented CSV and metadata-only QR bytes produce the unchanged dry-run output and remain byte-identical. No customer CSV/QR/backup contents or successful apply writer was exercised.
- Removing the guard and restoring the original apply-flag placement reconstructs the original script exactly. New-test Pint and both PHP syntax checks passed. All 360 captured pre-existing paths were compared and additions checked; only the recovery entry guard/flag ordering, root README and task docs changed, with one new test file. Application models/controllers/schemas/routes, default seeder, other scripts, public/index.php, .env/app key, Git index/ignore rules, builds and dependencies were preserved. Runtime/documented registration matched all 97 method/path pairs; existing read-only CLI options/exits, 31-table empty-schema inventory, local links and whitespace passed. Independent read-only source/test review found no blocking issue.

Files: [recovery script](../scripts/recover-wedding-data.php), [CLI safety tests](../tests/Feature/RecoveryScriptSafetyTest.php), [setup](../README.md), [scope](RECOVERY_SCRIPT_SAFETY.md), this plan and affected operator/security/test/phase references.

Limits: The effective Laravel environment is trusted configuration, not database identity or owner approval. Executable CLI cases used absent config caches; cached deployment behavior was not separately exercised. Allowed local/testing behavior retains the legacy writer, including known-password accounts and fixed event-ID1 overwrite; this task does not establish safe successful apply, concurrency, production provisioning or real recovery. Existing credentials/history/private backup access, packaging and restoration still require separate handling. Dry-run output and input errors can reveal supplied names/paths; they are unchanged. The metadata-only test asset does not prove PNG/QR decoding or authenticity. No actual configured/customer DB, recovery/seeding, service, account/ownership/grant, API/UI/Android/dependency change or deployment occurred. Real ownership/platform approval, customer database/private-file restore and maintenance fencing remain open; R09 stays partly addressed and Phase 3 remains IN PROGRESS.

## Task P3-020 — COMPLETE (2026-10-21)

Objective: return existing usage exit1 for absent/empty/repeated or wrong-type CSV/QR path options before file reading, preserving the effective-environment guard and valid input behavior.

Acceptance criteria:

- Resolve only nonempty string options and require files for both CSV inputs plus a directory for QR input; preserve fixed usage stderr/exit1.
- Keep the P3-019 environment guard first and preserve valid dry runs, CSV/QR parsing and the allowed writer body.
- Safely reproduce the original no-argument defect and verify twelve isolated invalid-input cases alongside all 21 prior cases with zero database/hash work and input conservation.
- Pass focused/full regression, formatting/syntax, exact source/test reconstruction, source/index/route/CLI and documentation checks.

Verification evidence:

- The pre-fix no-argument production read-only regression completed with one bootstrap and zero database/hash work, but exited 0 after a reader error instead of usage exit1 (1 test / 16 assertions / 1 failure, 29.934482s). After repair, focused safety checks passed **54 tests / 1,268 assertions** (42.461498s), and the complete isolated backend suite passed **882 tests / 14,272 assertions** (10544.650642s), with zero errors/failures/skips.
- Twelve new copied-script CLI cases cover no arguments, empty options, repeated base-CSV values, a directory in either CSV slot and a file in the QR-directory slot, each under production read-only and local apply. All return only the existing usage stderr and exit1, with zero connection/query/transaction/connector/hash calls and unchanged invented input bytes. All 21 prior environment/valid-dry-run cases remain present and pass, including the production apply refusal. No successful apply or customer input was executed.
- Only the strict nonempty-string path resolver and file/directory condition changed in the script; reversing those substitutions reconstructs the P3-019 script exactly. Its effective-environment guard and parser/writer body are unchanged. Removing the added test/provider block reconstructs the previous 21-case test file exactly. Scoped test Pint and both PHP syntax checks passed; independent read-only review found no blocker. All 362 pre-existing paths were compared: only this script, its existing test file and task docs changed. Root README/ignore rules, Git index, other source/tests/scripts/models/schemas/routes, public/index.php, .env/app key, builds and dependencies were preserved. All 97 documented route method/path pairs, read-only CLI options/exits, the 31-table inventory smoke and documentation links/whitespace passed.

Files: [recovery script](../scripts/recover-wedding-data.php), [CLI tests](../tests/Feature/RecoveryScriptSafetyTest.php), [scope](RECOVERY_SCRIPT_SAFETY.md), this plan and affected current/test/phase/operator references.

Limits: These are option-shape checks, not file readability, authenticity, ownership, race-free filesystem access or successful recovery proof. Recorded suite duration is not an application performance benchmark. Tests use exact TEMP copies, absent verified config caches, isolated storage, synthetic keys/inputs and throwing database connectors; cached deployment behavior and real/customer recovery remain unverified. Allowed local/testing writer behavior still includes known-password accounts and fixed event-ID1 overwrite. No configured/customer database, actual recovery/seeding, service, credential/account/ownership/grant, API/UI/Android/dependency change or deployment occurred. Real ownership/platform approval, customer database/private-file restore and maintenance fencing remain open; Phase 3 remains IN PROGRESS.

## Task P3-021 — COMPLETE (2026-10-21)

Objective: establish today's configured connection availability using only SELECT1 and preserve all source/settings/data/services.

Acceptance criteria:

- Reuse the reviewed fixed probe with existing mysql settings, preserved DB_* / DB_URL / key and bounded connection timeout.
- Attempt only SELECT1 after normal session setup; emit safe aggregate output, with no customer inventory or database/service changes.
- Record the actual exit/availability and preserve external rollout gates regardless of diagnostic completion.
- Compare source/index/build/dependency baseline, retain the current regression gate only after preservation, and validate documentation.

Verification evidence:

- On 2026-10-21 the unchanged reviewed SELECT-only TEMP probe returned sanitized database_unavailable / driver2002 / exit2 during connection setup. Cached application config was absent; no connection succeeded and zero application SELECT statements completed. No customer tables/owners were inspected; target identity and read-restricted privileges remain unverified. DB_* / DB_URL / .env / app key were preserved, and no service startup/shutdown, migration, seeding, recovery or grant command ran.
- The probe passed PHP syntax checks and emitted only the fixed diagnostic message. All 362 pre-existing paths and the Git index were compared; application/scripts/tests, root README/ignore rules, configuration, builds and dependencies were unchanged outside task docs. The P3-020 882-test /14,272-assertion source gate was retained after preservation; no new backend suite or application change is claimed. Documentation links and whitespace passed.

Files: TEMP-only probe/results in codex-harusi-p3021-20261021; this plan and affected phase/current/operator references.

Limits: A completed diagnostic is not an available database or completed Phase3. Real customer inventory still requires the intended target and a verified read-restricted account; legitimate owner/platform facts, customer database/private-file restoration and a controlled change window remain open. No ownership/grants were inferred; rollout_ready=false.

Statuses: NOT STARTED, IN PROGRESS, BLOCKED, READY FOR REVIEW, COMPLETED. Completion requires named successful checks; external gaps must remain visible.

## Current task contracts

| Field | P0-001 — documentation baseline | P1-001 — system audit |
|---|---|---|
| Goal | Establish four canonical docs and linked specialists | Record verified behavior, risks, reuse and migration dependencies |
| Scope | Markdown references/ADRs only | Code/schema/test/config inspection; isolated verification |
| Dependencies | Existing source/docs and user requirements | P0-001 conventions, inspected test isolation |
| Files | `docs/*.md`, `docs/decisions`, `docs/phases` | Same docs, read-only application files |
| Database changes | None | None; connection/schema read only, SQLite test migrations only |
| API impact | Document existing and target contracts | 88-route inventory; preserve shapes |
| UI impact | Document current and target | Source layout review; browser unavailable |
| Security impact | Record contracts, no runtime changes | Secrets withheld; isolation/crypto/media risks recorded |
| Acceptance | All requested documents resolve and distinguish states | All audit domains covered; contradictions and unverified areas explicit |
| Tests | Link/structure/state validation | Existing PHPUnit baseline, temporary auth probes, build to temporary directory |
| Status | COMPLETED —43docs/link/state/whitespace checks passed | COMPLETED —repository scope;baseline failure and external gaps explicitly recorded |

Phase 3 task contract: [inventory/preflight](phases/PHASE_03_WORKSPACES.md).

Exact Phase 2 task fields and tests: [authentication execution contracts](phases/PHASE_02_AUTHENTICATION.md). Migration acceptance gates: [migration plan](SAAS_MIGRATION_PLAN.md). Evidence/results: [phase report](PHASE_01_REPORT.md).

## PLANNED — ordered roadmap

Pending phases below are NOT STARTED until their concrete task contracts are opened; completed and active phases retain the execution statuses above. Later phases require a specialist task contract containing goal, scope, dependencies, files, database/API/UI/security impacts, acceptance criteria, tests and status before implementation.

| Phase / ID | Scope | Depends on / completion gate |
|---|---|---|
| 2 / P2 | Shared email-or-phone identity, recovery, tokens | P1; existing scanner login preserved, auth regressions pass |
| 3 / P3 | Workspaces/memberships/ownership migration | P2; backed-up additive schema and verified owner mapping |
| 4 / P4 | Tenant policies/scoping | P3; cross-workspace/resource/file isolation tests |
| 5 / P5 | Shared event/guest/import/QR/admission/report contracts; invitation/RSVP boundaries | P4; compatible web behavior, separate RSVP/admission semantics and capacity/retry tests |
| 6 / P6 | Product-wide design system, SW/EN; simple shared patterns for all interfaces | P5; screen/task inventory, representative existing-screen migration, translated components and full viewport acceptance |
| 7 / P7 | Original public website | P6; responsive/accessible original content and valid CTAs |
| 8 / P8 | Customer portal and clear next setup action | P4/P6; clear workspace/event context, authorized navigation; later preview/count capabilities gated |
| 9 / P9 | Event-type grid, minimal event details, guest add/import and QR/report self-service | P8/P5; authorized persisted event/guest data; preview handoff enabled only after P17 |
| 10 / P10 | Registration/onboarding and saved-flow resume | P9/P4; tenant security verified before registration; complete invitation journey also requires P17/P19/P24 |
| 11 / P11 | Owner/manager teams | P10; invite/revoke/role boundaries verified |
| 12 / P12 | Scanner team self-service | P11; assignments separate from workspace privileges |
| 13 / P13 | Scanner backend unification | P5/P12; all clients share admission logic |
| 14 / P14 | Android identity/scanner APIs | P2/P13; versioned auth and scanner contract tests |
| 15 / P15 | Android/offline integration | P14; device source, interoperability and recovery proven |
| 16 / P16 | Invitation schema/render engine, suitable default design and guest-access/RSVP persistence contracts | P4/P5; authoritative event/guest/QR binding, versioning and additive migration proof |
| 17 / P17 | Instant default preview, simplified template selection and Quick Edit studio | P16/P6/P9; details → preview → guests, without mandatory advanced editor |
| 18 / P18 | Custom PNG/JPEG backgrounds | P17; safe asset upload/dynamic overlays |
| 19 / P19 | Guest personalization, secure invitation page, RSVP, authorized QR ticket and summaries | P18/P9/P5; exact guest/QR/code association, access/reply isolation and separate counts |
| 20 / P20 | Single/bulk exports | P19; protected jobs/files, completion/retry checks |
| 21 / P21 | Five original templates | P20; every design render/QR/overflow verified |
| 22 / P22 | Twenty templates | P21; validated catalogue, not recoloring counts |
| 23 / P23 | One hundred templates | P22; every distinct template validated |
| 24 / P24 | Meta delivery workflows, send progress and live customer summaries | P20/P4/P19; secure links, consent, signed webhooks, retry/idempotency/usage and truthful progress |
| 25 / P25 | Plans/billing/entitlements | P10/P24; stable self-service, tenant security, exact money |
| 26 / P26 | Agency features | P25; multiple events and organizer permissions |
| 27 / P27 | Production hardening and product-wide usability | All required phases; security/performance/bilingual/device/hosting and task-completion checks across implemented surfaces |

No roadmap row implies that code, Android integration, templates or paid billing is already implemented.

### PLANNED — additive customer-experience tasks (2026-10-25)

All rows below are **NOT STARTED**. They extend pending phases without restarting Phase 0, changing P4-020 or replacing the 5 → 20 → 100 original-template stages. [Task scope, affected files and acceptance](phases/CUSTOMER_EXPERIENCE_INTEGRATION.md) govern implementation; [screen mapping](REFERENCE_UX_FLOW.md) is the UX reference.

| Task | Deliverable / user requirement | Dependencies and completion gate |
|---|---|---|
| P5-UX-01 | Reusable event, invitation, RSVP, reporting and admission service contracts | P4 isolation; inspect existing code before extraction; preserve QR/codes/capacity and define distinct status models |
| P6-UX-01 | Bilingual shared components for every interface | P5-UX-01; consistent Kadify tokens, essential forms, task hierarchy, status/recovery, SW/EN and all nine viewport sizes |
| P6-UX-02 | Product-wide screen inventory and simplicity rollout contract | P6-UX-01; record each role/screen/task, remove redundant inputs/steps, specify essential versus advanced controls, migrate representative existing screens and assign the remainder to owning phases; preserve security/business behavior |
| P8-UX-01 | Customer dashboard shell with Continue setup | P4/P6-UX-01; authorized server-derived next action; unfinished capabilities not advertised as usable |
| P9-UX-01 | A: one-tap visual event-category selection | P8-UX-01; four initial categories plus additional choices, distinct from template catalogue |
| P9-UX-02 | B: minimal event details persisted to real Event | P9-UX-01/P5-UX-01; names/date-time/venue, safe retries, explicit timezone and legacy-date preservation |
| P9-UX-03 | E: add/import/select guests | P9-UX-02; reuse Excel/CSV validation, exact event/pass associations and preserved design on return |
| P10-UX-01 | Registration/sign-in to saved setup journey | P9/P4; shared identity and automatic creator ownership, no platform-admin intervention; invitation activation waits for P17/P19/P24 |
| P16-UX-01 | Versioned engine/default design and additive invitation/RSVP data contracts | P4/P5-UX-01/P9-UX-02; reviewed schema and protected rendering, suitable original default, no disconnected event copy |
| P17-UX-01 | C: instant invitation preview and Send to guests handoff | P16-UX-01/P6-UX-01/P9-UX-03; render saved details, guest-aware next action, no advanced-editor requirement |
| P17-UX-02 | D: simplified catalogue and Quick Edit | P17-UX-01; change design/details/colors/text, preview/save, optional layout; retain full 100-template engine scope |
| P19-UX-01 | G: secure personal invitation page | P18/P9-UX-03; guest-specific hashed capability, revocation/expiry, protected media, no guest account |
| P19-UX-02 | H: yes/no RSVP and controlled changes | P19-UX-01; unique response, timestamps/source/version, idempotency/concurrency/deadline and no pass mutation |
| P19-UX-03 | I: authorized existing QR ticket | P19-UX-02/P5/P13; same QR/pass/event, allowance/table, legacy default preserved, scanner compatibility |
| P19-UX-04 | J: server-derived RSVP summaries | P19-UX-02; invited/attending/declined/pending distinct from admissions and delivery; exact count units |
| P24-UX-01 | Recipient/message confirmation and queued Meta send | P20/P19-UX-01/P9-UX-03; approved templates, consent, usage reservations, tenant checks and retry provenance; manual fallback |
| P24-UX-02 | F: honest batch progress and recipient status | P24-UX-01; queued/processing/sent/delivered/failed/skipped, verified webhooks and duplicate/out-of-order reconciliation |
| P24-UX-03 | K: live dashboard confirmations and next action | P19-UX-04/P24-UX-02/P8-UX-01; authorized bounded refresh, separate RSVP/message/admission cards |
| P27-UX-01 | L: complete customer → guest → scanner acceptance | All enabled above plus P13–P15 device gates; SW/EN nine-width QA, actual hosting queues, QR scanning and security/regression evidence |
| P27-UX-02 | Product-wide consistency and ease-of-use acceptance | P6-UX-02 plus each implemented surface's owning phase; representative unassisted tasks, understandable next actions, mistakes/recovery and SW/EN responsive evidence; refine observed friction rather than certify visual similarity |

Phase order remains intact. P9 can prepare persisted event/guest foundations before P16, but cannot claim the immediate-preview experience complete until P17 passes. P10 can prepare registration contracts, but the complete advertised journey requires P17/P19/P24. P16/P17 validate an original usable default through the existing engine; it is not a replacement for the later five, twenty and one hundred distinct-template quality gates. Phase 24 must not send guest links before P19 access and RSVP protections are verified.

The 2026-10-26 all-interface clarification expands this table to **20 NOT STARTED tasks**. Every future UI task inherits the design-system simplicity criteria, including public/auth, customer, administrator, scanner web/Android, reports, settings, billing, agency and support surfaces. P6-UX-02 defines the inventory and reuse pattern; each module performs its redesign in its existing phase. P27-UX-02 checks consistency across completed modules. Do not reopen completed backend phases, launch unavailable features or replace operational tables with arbitrary extra steps merely to resemble RSVP.

## Task P3-022 — COMPLETE (2026-10-21)

Objective: prepare automatic ownership for a new customer workspace so a person, organization or planner can become responsible through self-service creation without a developer-prepared ownership file.

Acceptance criteria:

- Validate trusted creator identity, UTF-8 workspace name and canonical replay key before connecting; do not infer ownership from names or legacy roles.
- Prepare an empty restrictive/unique private creation ledger and expand count-only inventory; preserve existing rows and refuse unsafe/partial migration or populated rollback.
- Atomically create one new workspace, active creator owner and receipt on the explicit primary connection; preserve all accounts, roles/platform flags, old workspaces/events and scanner assignments.
- Exact retry writes nothing; changed payload and lost authority refuse without restoring access. Different keys/accounts do not adopt same-named workspaces.
- Roll back all three inserts after any failure; reject ambient transactions, unsafe schema/keys/allocators and raw errors. Keep the service dormant until customer identity and tenant action/resource enforcement are ready.
- Verify strict inputs, boundaries/revocation, primary routing, allocator bounds, private output, migration/rollback and full fixture conservation; run focused/full regression, Pint/syntax, route/CLI and documentation/source preservation checks.

Verification evidence:

Focused workspace/ownership checks passed **287 tests / 8,225 assertions** (24.943250s); the complete isolated backend suite passed **938 tests / 15,017 assertions** (145.771976s), with zero errors/failures/skips. Task-scoped Pint and PHP syntax passed. Registered routes remain 97, and the bounded count-only inventory now catalogs 32 tables.

The initial migration test harness used a nonexistent Laravel setConnection method; it was corrected to select the isolated fixture through the actual protected migration property. A throwing-read-PDO regression then exposed Builder::hasTable using its default read connection. The new guard explicitly uses primary scalar metadata; the same test passes. Faults after each completed insert leave no partial workspace/owner/receipt; retry succeeds. Existing test changes outside the new files are limited to the new inventory catalog entry and four count assertions.

Limits: The source service has no HTTP/CLI/job caller and creates no accounts or events. Existing admin/scanner roles, platform flags, scanner assignments, credentials, QR/manual codes, admission/offline history and legacy ledgers remain unchanged. Source tests use invented data and isolated SQLite connections, array messaging/cache/session and TEMP storage. The configured database remains unavailable at the P3-021 diagnostic; no configured migration, real workspace/member grant, service/dependency/build change or deployment occurred. Actual MySQL provisioning contention/DDL, customer database/private-file restoration, rollout fencing, tenant action/resource policies, customer identity/portal and public registration are not established by this task. [Detailed contract and evidence](WORKSPACE_PROVISIONING.md). Phase3 remains IN PROGRESS; the automatic in-app workflow is not deployed.

## Task P4-001 — COMPLETE (2026-10-21)

Objective: prepare explicit workspace/event permissions for customer self-service while keeping existing global authority and unknown legacy events outside customer ownership.

Acceptance criteria:

- Validate explicit positive PHP integer account/workspace IDs, exact supported action and required/absent event ID shape before any connection.
- Limit owners/managers to the five recorded actions; workspace rename is owner-only, and unknown actions deny. No global role/platform/scanner or caller-facts bypass.
- Reread fresh primary account/member state and additionally verify the exact event/workspace parent for event view/update. Deny null, foreign, missing, malformed or unavailable state privately.
- Preserve every row/schema and caller transaction; use no writes/locks/default selection and no runtime caller. Keep neutral customer identity, complete resources/files and mutation fencing as subsequent tasks.
- Prove two-workspace/action boundaries, revocation, strict inputs, primary/default traps, malformed parent/schema, private failures and conservation; run focused/full suite, Pint/syntax, route/CLI and source/index/docs checks.

Verification evidence:

The complete isolated backend suite passed **976 tests / 17,366 assertions** (89.569317s), with zero errors/failures/skips. Focused access/authority/provisioning checks passed **152 tests / 5,286 assertions** (3.592400s), including 38 new access cases. New-file Pint and both PHP syntax checks passed; all 97 registered method/path pairs and the 32-table read-only CLI smoke remain compatible.

All 370 captured pre-existing paths were compared; only task docs changed and the access service/test were added. Existing provisioning/authority/backfill/auth/scanner code and tests, configuration, data/build/dependency files and Git index were preserved. Local documentation links and whitespace passed.

Limits: This service has no runtime caller and does not protect the existing global-admin routes, child-resource/files/import/export/report/offline surfaces or public/static QR assets. It adds no customer account, registration, portal, route/controller/middleware/policy wiring, schema, write, grant or deployment. Future writes need current locked account/member/resource checks in the same transaction as the mutation; a retained boolean is never a revocation fence. Caller isolation/snapshots remain in effect and the separate reads are not an atomic authority snapshot. Event lifecycle/field validation is separate. Phase3 actual configured database/legacy ownership/restore/fencing gates remain open; synthetic MySQL provisioning checks and safe neutral customer identity are also required before runtime exposure. [Detailed evidence](WORKSPACE_ACCESS.md). Phase4 remains IN PROGRESS.

## Task P4-002 — COMPLETE (2026-10-22)

Objective: prepare exact guest/QR ownership checks so valid event permission cannot be reused with a mismatched guest or pass.

Acceptance criteria:

- Validate explicit positive PHP integer user/workspace/event/guest IDs, exact action and required/absent QR ID before any connection.
- Delegate the four supported actions to fresh event view/update checks; grant no global/platform/scanner/code/token bypass or unknown action.
- Require safe resource primary keys and a bounded primary identity-only join matching the complete workspace/event/guest/QR lineage, including same-event QR/guest swaps.
- Deny missing/unowned/foreign/dangling/malformed parents, schema and raw failures privately; preserve rows/schema, caller transactions and default connection.
- Keep the service dormant with no file/write/route operation. Verify two-workspace positives/negatives, parent/member/account changes, strict inputs, primary traps, delegation and conservation; run focused/full suite, Pint/syntax, route/CLI and source/index/docs checks.

Verification evidence:

The complete isolated suite passed **1,011 tests / 19,736 assertions** (256.576754s), with zero errors/failures/skips. Focused guest/workspace/authority checks passed **143 tests / 6,978 assertions** (53.414269s), including 35 new guest/QR cases. New-file Pint, both PHP syntax checks, all 97 registered route method/path pairs and the 32-table read-only CLI smoke passed.

The first full run completed1011 tests with one existing recovery CLI subprocess timeout (30-second limit), zero assertion failures and no new guest/QR error. That exact production-apply case then passed alone (1 test/18 assertions); the complete suite was rerun without changing source/tests/timeouts or excluding cases. The timeout is recorded as an observed test-environment limit; its underlying cause is not established.

All 375 captured pre-existing paths were compared; existing application/tests/config/build/dependencies/index were preserved outside task docs. Only GuestAccess, its tests and its reference guide were added. Local links and whitespace passed.

Limits: GuestAccess has no runtime caller and adds no controller/policy/route/file delivery, account, schema, write, grant or deployment. Its booleans are point-in-time observations, not mutation authorization or revocation fencing; future writes need fresh locked account/member/lineage checks in the SAME write transaction. Caller isolation is preserved; authority and resource reads are not one atomic snapshot. Existing global-admin endpoints and static QR/files remain unchanged and must be isolated before customer exposure. Bulk/create/delete/import/export/reports/history/notifications/scanner/offline actions, neutral customer identity and actual database/restore/legacy mapping/fencing remain open. No configured customer DB, private files, service, dependency or existing build was changed. [Detailed contract](GUEST_ACCESS.md). Phase4 remains IN PROGRESS.

## Task P4-003 — COMPLETE (2026-10-22)

Objective: connect a distinct customer web identity to a safe read-only own-workspace home while preserving staff/scanner behavior and keeping signup and customer writers closed.

Acceptance criteria:

- Add exact customer web role and safe raw customer identity range checks; never create/convert accounts or grant staff/scanner/platform capability. Keep bearer eligibility explicitly restricted to active legacy admin/scanner.
- Apply existing email/phone/remember/session/intended-path and registered-email recovery/reset rules coherently; primary account refresh precedes role checks, and new identity clears selected workspace/event/import context.
- Add protected GET /customer with only own active owner/manager memberships and real parents, fresh account predicates, fixed pagination, escaped EN/SW independent HTML and private response headers. Read no event/guest/file data or admin layout.
- Hide customer/unsupported accounts from legacy staff index and reject bound CRUD before validation, rechecking locked roles before writes; retain normal staff CRUD and token revocation with no platform bypass or customer creation via staff forms.
- Permit active customers in dormant new-workspace/authority services without changing legacy backfill eligibility.
- Verify identity/recovery/device/staff route and own-workspace isolation, malformed IDs, primary traps, target-role transitions, exact fixture conservation and actual array mail; run focused/full suites, PHP Pint/syntax, route/CLI and source/index/docs checks.

Verification evidence:

Full isolated regression passed **1,071 tests / 20,461 assertions** (170.769364s), with zero errors/failures/skips. Focused identity/home/auth/recovery/token/ownership regression passed **378 tests / 9,730 assertions** (55.085574s). The 60 new cases comprise 41 identity cases / 439 assertions and 19 home/admin-boundary cases / 286 assertions; both classes passed in the full run. Two cached raw-ID alias regressions first reproduced an incorrect HTTP 200 (expected 401), then passed with zero account lookups/writes and exact fixture/remember-token preservation after the raw-ID check was moved before the primary reread. Task PHP Pint/syntax, all 98 runtime route method/path pairs and the unchanged 32-table safe read-only CLI smoke passed.

All 378 pre-existing paths were compared; only the eleven contracted PHP files and affected docs changed. The new controller/view/two locale dictionaries/two tests/reference were added. Existing data/settings/app key, builds/dependencies, unrelated source/tests and Git index were preserved. The default source/asset paths and new lang directory were checked; documentation links/whitespace passed.

Limits: This adds no signup, customer-account creation, workspace/event/guest/file writer, membership activation/grant or real-data migration. No configured account, ownership, password, token, environment/app key, service, dependency or existing build was changed. Existing legacy event/switcher/report/file/static-QR surfaces remain global and need isolation before customer event/writer exposure. Full customer portal/onboarding, complete action/resource policies, actual MySQL provisioning DDL/contention, actual database/private-file restore/legacy mapping/fencing, deployed SMTP/hosting and real browser/device/bilingual layout validation remain open. HTTP/HTML/DOM/source checks are not visual/device proof. Denied bearer requests may update only Sanctum's existing last_used_at/updated_at telemetry before middleware rejects; token identity/hash/abilities/expiry remain unchanged and no customer token is issued. Controlled target-role transition tests are fixture schedules, not concurrent-engine proof. Existing device middleware still uses default-read account refresh; primary-only web tests do not establish replica-safe bearer revocation. [Details](CUSTOMER_WEB_IDENTITY.md). Phase4 remains IN PROGRESS.


## Task P4-004 — COMPLETE (2026-10-22)

Objective: exclude workspace-owned events from the legacy administrator catalogue, switcher and direct metadata controls, preserving ordinary unowned event behavior and keeping customer writers closed.

Acceptance criteria:

- Limit catalogue/statistics and primary layout event queries to null ownership; ignore unsafe selected IDs and preserve session context. No platform/member bypass or global Event scope.
- Fresh primary null-ownership checks precede edit/update/select/archive/cancel validation or navigation. Reject ambiguous/out-of-range raw bound IDs before a cast can alias another event.
- Repeat null ownership under a row lock in the same update/archive/cancel transaction; refuse reassigned targets without metadata, ownership or unrelated fixture/session writes.
- Preserve legacy event creation and unowned controls; ignore submitted workspace ownership and never adopt/reassign events.
- Verify two workspaces, all private lifecycle states/direct verbs, privileged negatives, raw aliases, primary reads, stale/locked transitions and conservation; run focused/full suites, scoped formatting/syntax, route/CLI and preservation checks.

Verification evidence:

The full isolated suite passed **1,110 tests / 21,253 assertions** (169.657078s), with zero errors/failures/skips. Focused catalogue/control/legacy compatibility checks passed **131 tests / 1,614 assertions** (7.089580s), including 39 new cases / 794 assertions. Two catalogue cases first reproduced private event names in legacy HTML. A final review also added SQL range bounds before catalogue/switcher hydration to prevent unsupported stored IDs from generating aliased links/statistics; the added regression first reproduced zero/negative catalogue rows, then verified their exclusion and the bound upper limit. All new cases then passed. The first focused run also identified default-event fixture expectations and one obsolete allowed-owned-update assertion; the fixture now retains the migration-created default event, and the existing assertion requires404 with exact event-row preservation. Scoped Pint, four PHP syntax checks, unchanged 98 route method/path pairs and safe 32-table read-only CLI checks passed.

Compared all 385 pre-existing paths. Only the two contracted application files, one existing ownership-test method and task docs changed; one new test class and one guide were added. The existing test's other methods match after line-ending normalization. Environment/app key, dependencies/builds, public/index.php, other source/tests/migrations and Git index were preserved. Local links and whitespace passed.

Limits: This is only the catalogue/layout and direct event metadata slice. EnsureAdminEventSelected, AdminController::currentEvent, direct offline session routes, account event choices/assigned scanner administration, defaultEvent, child-resource/import/export/report/file/static-QR and scanner paths remain next-task boundaries. Filtering the layout neither authorizes those paths nor revokes existing selected context; read/selection observations do not fence concurrent reparenting. The composer's existing offline-session metadata service still uses default reads, so the entire layout is not proven replica-independent. Reassignment tests are controlled SQLite fixture schedules, not actual MySQL concurrency. SQLite cannot store MySQL unsigned overflow IDs; the upper query limit is verified through bound SQL parameters, not an actual MySQL overflow catalogue case. Customer writers/signup remain closed. Actual configured database/schema, legacy mapping/restore/fencing, MySQL provisioning, hosting/SMTP and browser/device checks remain unverified. The prepared workspace_id migration must exist before these filtered queries can run; no permissive fallback to an unknown schema is added. No real data/configuration/service/account/grant/file/dependency/build change or deployment occurred. [Details](LEGACY_EVENT_ADMINISTRATION.md). Phase4 remains IN PROGRESS.

## Task P4-005 — COMPLETE (2026-10-22)

Objective: reject invalid or workspace-owned legacy selected/direct offline entry context, remove administrator default-event fallback and discard stale guest import state on event changes.

Acceptance criteria:

- Resolve canonical positive PHP-range event IDs through fresh primary null-ownership checks; sole-active lookup is bounded to two safe active legacy candidates and never creates defaults.
- Reject every invalid explicit session key, including null, without falling back; clear selected/import state and preserve unrelated context. Auto-selection is allowed only for an absent key and a single safe legacy event.
- Recheck current event and cached import preview after the gateway; missing/deleted/owned/ambiguous context404s without default recreation or resource writes.
- Refresh direct offline create/store raw-bound targets before navigation/validation/snapshot/package work, use the fresh model and deny private/unsafe targets without effects.
- Clear import rows/token/result when actual event context changes or current selection is removed; preserve same-event imports and unrelated state.
- Verify malformed/aliased IDs, both workspaces, candidate counts, primary traps, errors, controlled context transitions, service spies and fixture/session conservation; run focused/full/Pint/syntax/routes/CLI/source/index/docs checks.

Verification evidence:

The full isolated suite passed **1,172 tests / 21,736 assertions** (156.235597s), with zero errors/failures/skips. Focused selected-event/legacy/import/offline compatibility passed **219 tests / 2,439 assertions** (103.582353s), including 62 new cases / 483 assertions. All pre-existing full-suite class counts/assertions remain unchanged. The baseline owned-dashboard probe first returned HTTP200 instead of redirect; four further review cases reproduced explicit-null fallback (the next callback ran with 209 instead of redirect302) and three unchecked post-gateway import contexts. Those cases passed after the fixes. New resolver/middleware and six exact edited method fragments passed scoped Pint; the new test passed Pint and all seven task PHP files passed syntax. The same 98 method/path pairs and safe 32-table read-only CLI checks passed.

Compared all 387 pre-existing paths. Only five contracted application files and task docs changed. Added one resolver, one test class and this task's guide. Existing controller/trait content outside six exact edited methods plus their added imports is preserved after line-ending normalization; the offline controller differs only at two fresh-event assignments. All existing tests/migrations/routes, environment/app key, public/index.php, dependencies/builds and Git index were preserved. Local documentation links and whitespace passed.

The earlier usage-limit checkpoint is superseded by this completed verification. No existing test was amended.

Limits: These are request-entry and point-in-time context observations. Downstream guest/offline/file writers do not gain Event locks or atomic reparenting/revocation fences. Offline package/session/import/conflict lineage, file download/delete/audit ordering and relationship freshness remain separate work; unchanged helper/lock order and pretransaction audit effects are not certified. Global Event::defaultEvent and other model/scanner fallbacks, legacy staff/scanner event choices and public/static assets remain unchanged. The primary clone proves the resolver query paths, not every downstream relationship or offline metadata read. SQLite cannot insert MySQL unsigned overflow IDs; upper query bounds are checked through SQL bindings. Controlled post-gateway transitions are fixtures, not actual MySQL concurrency. Customer writers/signup stay closed. Actual configured database/schema, legacy ownership/restore/fencing, MySQL provisioning, hosting/SMTP and browser/device/bilingual visual checks remain unverified. No real data, environment/app key, account/owner/grant, service, dependency or existing build was changed, and no deployment occurred. [Details](LEGACY_SELECTED_EVENT.md). Phase4 remains IN PROGRESS.

## Task P4-006 — COMPLETE (2026-10-22)

Objective: prevent legacy staff credential management from exposing or rewriting workspace/platform identities, and make scanner assignment use only safe active unowned events.

Acceptance criteria:

- Require canonical positive PHP-range raw user IDs, exact staff role, false platform capability, no membership of any state, and null or actual safe unowned event assignment. Apply primary catalogue/direct-target boundaries with no actor bypass.
- Restrict event choices and assignments to active unowned events; use sole-active fallback only when unambiguous and return generic validation for zero/multiple/unsafe parents without default creation.
- Lock old/proposed parents in exact ascending integer order before the User; recheck current role/flag/assignment and use a locking membership-row observation before credentials/account/token writes. Refuse assignment drift and preserve rollback/token/session behavior.
- Preserve legacy positives, self-protection, unrelated session/data and source; deliberately strengthen only the foundation test's protected-platform-target expectation.
- Verify focused/full isolated regression, primary traps, controlled transitions, rollback/conservation, PHP formatting/syntax, unchanged routes/CLI, environment/index and documentation references.

Verification evidence:

Full isolated regression passed **1,240 tests / 22,336 assertions** (188.002481s), with zero errors/failures/skips. Focused staff/customer/auth/recovery/token/assignment/offline compatibility passed **298 tests / 3,308 assertions** (68.903706s), including 68 new cases / 601 assertions. The old catalogue first reproduced private-account exposure before the controller was installed. Scoped controller/new-test Pint and task PHP syntax passed. All 98 route method/path pairs and safe 32-table CLI inventory/inspection behavior remain unchanged.

All 390 task-start paths were compared. Contracted existing non-doc changes are AdminUserController, two assigned-event form text substitutions and one exact foundation test block. Added one new feature test and this guide; all other existing tests, application files, routes/migrations/models/defaults, public/index.php, configuration, dependencies/builds and Git index remain preserved. Documentation links and whitespace passed.

Legacy staff account management now excludes every workspace-linked or platform target, including inactive membership rows and scanner/admin identities assigned to owned events. Targets require a safe canonical positive PHP-range raw user ID, exact admin/scanner role, known-false raw platform flag, no membership of any state and either null assignment or an actual safe unowned event. The primary catalogue applies these predicates before pagination and constrains its eager event relationship to safe unowned parents. Direct read/write targets are refreshed before validation; an actor's platform flag or memberships never bypass the target boundary. Inactive ordinary legacy accounts remain manageable.

Store explicitly supplies the eligible scanner event and locks its parent before inserting the User, avoiding the unchanged model default hook. Update/delete capture the old assignment, lock deduplicated old/proposed Events in exact ascending integer order, then lock User. They recheck raw identity, exact role/false platform flag and unchanged old assignment, followed by a current locking membership-row read. Assignment drift denies instead of taking a newly discovered Event lock after User. Only then do account/credential/token writes occur in the same transaction. Existing password hashing, remember-token rotation on password reset, token revocation and rollback remain intact.

Limits: This source task follows live offline package Event-before-User ordering and current compliant membership writers' User-before-membership protocol; it does not prove actual MySQL blocking or protect arbitrary external SQL/future writers. The dormant legacy backfill still requires its maintenance fence. Primary list/edit queries are point-in-time observations; the mutation locks cover these staff writes only. Integer PK/FK schemas prepared by repository migrations are assumed for SQL list filtering; raw bound identities are checked separately. SQLite cannot exercise MySQL unsigned overflow values or collation/locking behavior. Global model/scanner defaultEvent fallbacks, device/recovery code, scanner admission and remaining guest/offline/file lineage/write/audit/download/delete boundaries stay unchanged. Customer writers/signup remain closed. Actual configured database/schema, legitimate legacy ownership/restore/fencing, MySQL provisioning and hosting/SMTP/browser/device checks remain unverified. No real account/credential/owner/grant/data/configuration/app-key/service/dependency/build change or deployment occurred. [Details](LEGACY_STAFF_ACCOUNTS.md). Phase4 remains IN PROGRESS.

## Task P4-007 — COMPLETE (2026-10-22)

Objective: prove selected staff parent/account/membership lock behavior, current membership visibility and interrupted credential rollback on a separate disposable MySQL server.

Acceptance criteria:

- Identify the signed MySQL binary and use only a fresh private loopback server/schema/account with verified target identity, scoped credentials and the current frozen migrations; preserve configured data and other services.
- Observe exact primary-row waits for parent adoption, an older caller repeatable-read snapshot and a compliant member writer; require generic refusal/zero DML for protected targets and exact allowed mutation deltas for successful credential changes.
- Interrupt only a verified owned worker after credentials/token deletion are uncommitted; verify thread disappearance, complete rollback and successful retry.
- Verify actual case/padding collation and unsigned overflow catalogue behavior with safe maximum-ID positive controls and read conservation.
- Stop owned workers/server, release the port, remove generated plaintext credentials and validate source/index/environment/routes/CLI/docs without claiming real rollout or all possible schedules.

Verification evidence:

All **four selected actual MySQL 8.4.11 cases / nine independent PHP workers** passed. Three waits were observed through performance_schema with exact requesting/blocking connection IDs, schema/table, PRIMARY index, WAITING RECORD state and canonical target key (Event301 or User221). Every synthetic schema applied the frozen 28 repository migrations and had 32 tables. Whole-fixture snapshots and table definitions were conserved outside the exact allowed changes; allocator counters were excluded from schema comparison and their restoration is not claimed.

The existing Oracle-signed binary was reused read-only. A new ACL-restricted TEMP data directory and generated scoped credentials used loopback43494, MySQLX disabled and verified UUID/datadir/port/bind/version before writes. All owned workers and the owned server were stopped; an independent process/port check passed, unavailable connection returned safe2002, and the four task-created plaintext credential files were removed. XAMPP, other project services/data and Harusi's configured database were untouched.

The **1,240 tests / 22,336 assertions** P4-006 source gate is retained after exact source/test preservation, not presented as a newly rerun suite. TEMP PHP formatting/syntax, unchanged 98 route method/path pairs, safe 32-table read-only CLI checks, Git index/environment, document links and whitespace passed.

| Case | Observed result |
|---|---|
| Scanner creation versus parent adoption | Parent validation saw an unowned event; the actual Event lock waited for the holder, then current ownership caused generic422 and zero staff DML. Only the holder's synthetic ownership change remained. |
| Older caller RR snapshot versus membership commit | The caller first observed no membership. Its User lock waited for an independent inactive-member insertion; the current locking membership read then denied404 with zero staff DML. Nested rollback preserved the caller level, followed by explicit outer rollback. |
| Credential update versus compliant membership writer | The member writer waited on the exact User until password/remember changes and target token revocation committed. Only the later inactive membership was added; a subsequent legacy update denied404. |
| Interrupted credential/token mutation | Only the verified owned worker was terminated after User update and token deletion were uncommitted. Its DB thread disappeared, the complete fixture returned to its prior state, and a fresh HTTP retry succeeded302 with exactly two DML statements. |

A fifth dedicated synthetic schema verified actual utf8mb4_unicode_ci case/padding equality before exercising the byte-exact role/status filters. Mixed-case/padded staff roles and active statuses were excluded. Real unsigned IDs of PHP_INT_MAX+1 were inserted as decimal strings and excluded from catalogue/options; a raw overflow bound target denied404, while canonical PHP_INT_MAX positive controls remained visible. Direct controller view-data reads executed zero DML and preserved the whole fixture/schema. This was not a browser/HTML/device test.

All 392 captured paths remain preserved outside task documentation, including application/tests/migrations, public/index.php, environment/app key, dependencies/builds and the Git index. Added one guide only. No backend suite rerun was needed because this task changes no application/test source.

Limits: These are selected schedules using invented data on a separate server, not a configured/customer database rollout or proof of every interleaving/arbitrary external writer. The dormant legacy backfill still requires its maintenance fence. Current 28-migration fresh DDL includes the empty provisioning receipt table; automatic provisioner transactions/contention/retries have not been rehearsed on MySQL by this task. Guest/offline/file lineage, write/audit/download/delete transactions, scanner fallbacks and public/static assets remain unfinished. Customer writers/signup remain closed. Actual legacy ownership/platform approval, database/private-file restore/fencing and hosting/SMTP/browser/device remain unverified. No application/test/migration/API/UI/model/default/dependency/build/environment/app-key or real account/credential/owner/grant changes and no deployment occurred. [Details](MYSQL_STAFF_REHEARSAL.md). Phase4 remains IN PROGRESS.

## Task P4-008 — COMPLETE (2026-10-23)

Objective: prove selected actual MySQL behavior for automatic new-workspace creator ownership, exact retries, account revocation and rollback without developer ownership files or legacy event adoption.

Acceptance criteria:

- Use only a fresh private loopback MySQL server, verified distribution/target identity, scoped generated credentials and current frozen migrations; preserve configured data and other services.
- Verify exactly three atomic inserts, Unicode matching retry with zero DML, name/key/creator separation, rename conservation and refusal of changed or revoked authority; preserve all unrelated data and global account fields.
- Prove same-creator and both revocation orderings through exact creator PRIMARY-row waits, with committed setup/refusal results and exact mutation conservation.
- Interrupt after each completed insert, prove exact row rollback and retry; exercise actual unsigned allocator overflow at each insert stage without returning an unsafe identity or promising allocator restoration.
- Verify primary-only explicit connection behavior, private result/errors, unchanged source/routes/CLI/index/environment/docs, and complete owned-process/port/plaintext-credential cleanup.

Verification evidence:

All **10 actual MySQL 8.4.11 cases / 13 independent service workers** passed, including 12 serial observations, three exact creator-row waits, interruption after each completed insert and three actual unsigned allocator cases. Eight fresh synthetic schemas applied the frozen 28 repository migrations and contained 32 tables. The complete P4-006 **1,240 tests / 22,336 assertions** source gate is retained by exact source/test preservation; no new backend suite run is claimed for this TEMP/docs-only task.

| Case group | Actual engine evidence |
|---|---|
| Serial creation and replay | Twelve observations verify exact three-row setup, normalized retry, creator/key/name separation, rename/revocation/hash refusal and explicit-primary behavior. |
| Same creator and exact request | The contender waits on users PRIMARY 221. One call commits three inserts; the second returns the same workspace with zero DML. |
| Creator revocation in both orders | Revocation-first refuses creation without setup. Creation-first commits its setup, then deactivation commits; a later retry refuses without resurrecting authority. |
| Worker interruption at three stages | Verified owned workers are terminated after workspace, owner-membership or receipt insert. Thread disappearance, exact complete row rollback and successful exact-request retry are verified separately at each stage. |
| Actual unsigned allocator IDs at three stages | Separate table allocators produce raw 9223372036854775808. Workspace/member/receipt stages refuse invalid_prerequisites after the expected 1/2/3 inserts, roll back every new row and return no unsafe receipt. |

An active customer creates exactly one new workspace, one active owner membership and one private hashed receipt in one transaction. Unicode-trimmed matching retries return the same workspace with zero DML. Changed payloads conflict; same-named workspaces are never adopted, and keys are scoped to the creator. Renames survive replay. Disabled/demoted owners, disabled creators and collation-equivalent corrupt request hashes refuse without repair or resurrection. Existing accounts, global roles/platform flags, event assignments/history and legacy ledgers remain conserved. Explicit primary metadata and row reads succeed with an unavailable read PDO and unconfigured global default; no replica attempt occurs. Receipt JSON/debug privacy also holds.

A new ACL-restricted TEMP MySQL data directory used only loopback 43495, MySQLX disabled, generated scoped credentials and the read-only Oracle-signed binary. UUID/datadir/port/bind/version were verified before fixture writes. Three waits matched both worker connection IDs and the exact users/PRIMARY/221 WAITING RECORD. Full row snapshots and table definitions were compared with only AUTO_INCREMENT counters normalized; ID reuse or allocator restoration is not claimed. All owned workers/server are stopped, the port is free, safe unavailable error 2002 was confirmed, and the four generated plaintext credential files were removed. Harusi's configured database, XAMPP and other services/data were untouched.

All 393 captured task-start paths are preserved outside task documentation; only one new guide was added. No backend suite was rerun because application/test source is unchanged. PHP/TEMP PowerShell syntax, formatting, routes, read-only CLI, Git index/environment and local documentation checks passed.

Limits: This is selected synthetic MySQL evidence, not a configured/customer database rollout or every possible concurrency schedule. The provisioner remains dormant with no HTTP/CLI/job application caller, public signup, customer account creation or event/resource writer. Future callers must derive creator identity from authenticated state and apply current action/parent authorization; request keys and creation receipts are not permission tokens. Legitimate legacy ownership/platform approval, real database/private-file restore/fencing and hosting/SMTP/browser/device remain unverified. Remaining scanner/guest/offline/file boundaries and customer event/signup exposure are still open. No application/test/migration/API/UI/default/model/credential/owner/grant/dependency/build/environment/app-key or real-data change or deployment occurred. [Details](MYSQL_WORKSPACE_PROVISIONING_REHEARSAL.md). Phase4 remains IN PROGRESS.


## Task P4-009 — COMPLETE (2026-10-23)

Status: COMPLETE (2026-10-23). Dependencies: P4-003 customer identity, P4-006 staff isolation and P4-008 actual provisioning engine evidence.

Objective: let an existing signed-in active customer create an empty workspace with automatic creator ownership, without developer files, account creation or legacy adoption.

Acceptance criteria:

- Add only GET /customer/workspaces/create and POST /customer/workspaces, session/exact-customer protected with web CSRF. Derive creator exclusively from a safe authenticated User identity; ignore submitted ownership, account, role, platform and event fields.
- Preserve the provisioner's generic internal contract while adding a customer-only entry that rechecks exact current customer role under the same creator row lock before replay or three-insert creation. Do not introduce an outer transaction or bypass schema/primary/raw-ID guards.
- Render an independent accessible EN/SW form and home action, escaping names and giving automatic-owner guidance. Generate a private UUIDv4 hidden request key on GET; retain valid keys for validation/transient retry. Success redirects home with localized generic created/already-created feedback.
- Use HTML form errors: invalid input422; changed/revoked receipt409 with a fresh form key and review guidance; unavailable prerequisites/database503 retains key; unavailable creator403. Private no-store/no-referrer headers on setup responses. No new JSON API.
- Bound submissions to 5/min per account and 20/min per IP, with generic localized429 and Retry-After. Verify CSRF/session expiry, stale/disabled/role-changed identity, cross-account injected inputs, exact replay, rollback, privacy and primary-only behavior on isolated fixtures.
- Run focused and full backend regression, formatting/syntax and exact 100-route registration; preserve existing contracts outside the two intended home-test assertions, source outside declared scope, .env/app key, dependencies/builds, configured data and Git index. Update plan and affected current references after checks.

Scope: CustomerWorkspaceController, a customer setup view, home CTA/status, two customer translation files, two routes/named limiter, a strict customer provisioning wrapper/locked role predicate, one new focused feature test and scoped existing home test amendments. Documentation may change. Public signup, account/event/guest/team/billing writers, workspace selection and legacy migration remain closed; browser/device/hosting and configured rollout are not established by backend tests.

Verification evidence:

The complete isolated backend suite passed **1,275 tests / 22,874 assertions** (223.641685s), with zero errors/failures/skips. Focused customer identity/home/provisioning checks passed **151 tests / 1,990 assertions** (82.372603s), including 35 new setup cases. Scoped PHP formatting and eight syntax checks passed. Runtime registration contains exactly 100 routes: the preserved 98 plus two customer setup method/path pairs; the safe inventory still catalogs 32 tables. Environment/app key, Git index, public/index.php, unrelated source, dependencies and existing builds remain preserved.

Checks exercise escaped localized form/owner guidance/labels/CSRF, exact route middleware, injected ownership/global fields, normalized replay and private receipt hashes, changed/revoked owner conflicts, 13 malformed input cases, staff/unsupported/guest/disabled/unsafe identities, three controlled post-gateway role/active transitions, rollback after each insert and successful retry, invalid prerequisites, unavailable read PDO, enforced CSRF/session expiry and both limiter buckets with a fixed test clock. Full row snapshots preserve foreign workspaces/events/guests/QR data, credentials, recovery tokens and device tokens. Mail/notifications/HTTP are fake and no actual delivery occurs.

An existing active customer can open the independent EN/SW setup form and create an empty workspace. The account's authenticated safe identity is the creator; submitted user/owner/workspace/event/role/platform fields supply no authority. The customer-only service entry rereads exact customer role and active state under the same creator row lock used by the three atomic workspace/owner/receipt inserts. Matching normalized requests return the existing setup with zero DML; the account remains a customer and receives no global/scanner/platform capability. Same-name legacy workspaces/events are never adopted.

| Outcome | Current browser behavior |
|---|---|
| Form GET |200 independent localized HTML; fresh hidden lowercase UUIDv4 key; no setup writes |
| New setup / matching retry |302 to customer.home; generic localized created/already-created status; exactly 3 inserts / zero DML |
| Invalid input |422 HTML with escaped field errors; valid retry key retained, malformed key replaced |
| Changed payload / revoked owner receipt |409 HTML with review guidance and a fresh key; no repair or resurrection |
| Unavailable schema/database |503 HTML; same valid key and name retained for retry; no SQL/error/bindings exposed |
| Creator changed after gateway |403 generic response after locked account recheck; no setup mutation |
| Submission limit |429 localized generic text with Retry-After; 5/min per authenticated account and 20/min per IP |

Limits: This source task exposes only empty-workspace creation for existing authenticated customers. Public signup/account creation and customer event/guest/team/file/billing writers remain closed. No configured database/schema/migration, real ownership/grant/data/credential, environment/app-key/service/dependency/build or deployment change occurred. Tests use SQLite memory clones outside ambient transactions and TEMP storage, array cache/session/mail, sync queue and null WhatsApp. Controlled transitions are deterministic fixtures, not actual concurrent HTTP MySQL schedules. P4-008 separately verifies selected shared engine transaction behavior; the new HTTP customer-role wrapper is not a fresh engine rehearsal. Browser/device/responsive visual acceptance, configured database/private-file restore, legitimate legacy ownership/platform approvals, maintenance fencing and hosting/SMTP remain unverified. Phase4 remains IN PROGRESS. [Details](CUSTOMER_WORKSPACE_SETUP.md).

## Task P4-010 — COMPLETE (2026-10-23)

Status: COMPLETE (2026-10-23). Dependencies: P4-005 fresh legacy context and P4-009 source gate.

Objective: reject owned, foreign, missing, malformed or inconsistent offline resource parents before controller policy-specific reads, relationship payloads, validation, audits, file/service effects. This is a bounded request-entry slice, not an atomic downstream mutation fence.

Acceptance criteria:

- Add a shared read-only primary resolver for session, package, result import and conflict heads, constrained through exact selected unowned Event lineage in a joined query. Strict positive canonical PHP-range IDs precede queries; validate raw returned IDs/parent fields and do not trust bound cached attributes or relations. Imports/conflicts must agree with the session Event; any linked conflict log must belong to that same import/session. Null log remains legitimate.
- Every bound offline controller action resolves a fresh matching head before resource policy/validation/payload/service/file/audit work. Preview resolves its submitted session after shape-only validation and before file validation/reads or upload verification. Generated package download also checks its package really belongs to the selected session. Preserve allowed lifecycle policies and existing successful flows.
- Reject private/inconsistent parents with generic 404 and no DML/file/audit/service effects; preserve selected/import session context. Existing route binding may already read the bound head; it is not certified as replica-independent here. Direct resolver primary reads must work without a read PDO, and stale model metadata cannot grant access.
- Add meaningful HTTP/resolver denial and fresh-state regressions, prove at least one denial on old code, run focused and full backend tests, formatting/syntax, unchanged 100-route and 32-table safe CLI checks, source/environment/index/build preservation and affected documentation.

Scope: one tenancy resolver, the shared offline admin trait and four offline admin controllers, one new boundary test and scoped existing assertion corrections only if a private denial deliberately changes earlier validation ordering. No schema, policy/lifecycle, service transaction/lock order, storage/audit implementation, scanner admission, customer setup/signup/event/guest writer or configured database change. Guest/scanner nested payload references, atomic reparenting/revocation during downstream transactions or filesystem work and direct public/static files remain separate boundaries.

Verification evidence:

The complete isolated backend suite passed **1,324 tests / 23,668 assertions** (308.478168s), with zero errors/failures/skips. Focused offline/selected-event checks passed **155 tests / 1,723 assertions** (24.357512s), including 49 new boundary cases. Seven scoped PHP syntax checks and final read-only Pint passed. All 100 route method/path pairs and the 32-table safe CLI remain unchanged. All 398 captured task-start paths outside declared source/docs, environment/app key, public/index.php, customer setup, dependencies/builds and Git index were preserved.

Both legacy/platform actors first reproduced private preview 422 before file validation; the retained old-code run records 2 tests / 2 failures / 0 errors, then the fixed cases pass. New checks cover all 15 bound actions, foreign unowned preview, mismatched import Event, four controlled parent changes after the earlier gateway, generated-package session mismatch, active/archived/cancelled history,14 malformed ID shapes before any query, eight broken parent/log links and optional null logs. Complete synthetic table/fake-file snapshots protect denials. A separate SQLite memory clone at transaction level 0 proves an ordinary read hits the failing replica control once, while all resolver reads make zero replica attempts and preserve source fixtures. Early test-only missing imports/active-session uniqueness issues were corrected; the package-ordering regression also moved the secondary package check before form validation.

A shared primary resolver now joins each offline session/package/result-import/conflict head to its exact selected unowned Event. Raw positive PHP-range IDs and every relevant returned parent identity are checked; malformed, owned, foreign, missing and inconsistent chains return no model. Imports/conflicts must agree with their session Event, and a linked conflict log must belong to the same import/session; a null log is valid. Internal join aliases are removed from the returned model. Bound cached attributes/relations cannot supply permission.

All 15 bound controller actions resolve a fresh matching head before resource policies, relationship payloads, validation or service/file/audit work. Preview validates the submitted session's shape, resolves its parent and then validates/reads the file. Generated-package downloads validate the raw package reference and require the package's actual session to match before form validation. Existing allowed lifecycle policies, successful flows and 100 routes are retained. Denied requests use generic 404 and preserve selected/import context with no observed DML, audit or invented-file change.

Limits: These are request-entry observations, not an authorization token, an atomic revocation/reparenting fence or a database rollout. Route model binding can already fetch a head before the controller and is not certified as replica-independent. Existing caller transactions/snapshots still govern visibility; primary reads do not override isolation. Related guest/scanner payload references, downstream Event/User/session/import/conflict lock order, pretransaction audit effects, filesystem download/archive/delete races and public/static files remain separate boundaries. Existing global actor eligibility is still checked by middleware/policies; this task does not add a locked actor refresh. No lifecycle policy, service transaction/storage/audit implementation, schema, migration, scanner admission, customer setup/event/signup writer, configured data/credential/environment/app key, service/dependency/build or deployment changed. SQLite controlled schedules do not prove MySQL concurrency. Browser/device/hosting/SMTP and actual database/ownership/restore/fencing remain unverified. Phase4 remains IN PROGRESS. [Details](LEGACY_OFFLINE_RESOURCES.md).

## Task P4-011 — COMPLETE (2026-10-23)

Status: COMPLETE (2026-10-23). Dependencies: P4-006 staff/membership protocol and P4-010 offline request-entry checks.

Objective: keep legacy Event ownership and actor/scanner eligibility valid during package generation, before guest snapshots, session/audit inserts, encryption or file writes.

Acceptance criteria:

- Add one internal guard used inside the existing package-generation transaction. Strict raw IDs precede row queries; lock the exact Event first and require current null ownership. Then lock deduplicated actor/master/client User IDs in exact ascending integer order before membership checks. Do not introduce an outer transaction or change package contents/signatures/crypto/session transition/compensation logic.
- Recheck raw eligible admin actor and active scanner/event identities under lock. Legacy scanner targets must have a false platform flag and no workspace membership of any role/activity; use current locking membership reads after the User lock. Refuse stale/disabled/role-changed/private targets without snapshot, DML or file effects. Parent404 and actor403 must survive the controller's business-error catches. Preserve existing generic invalid-scanner messages and valid flows.
- Align scanner choices and request existence validation with active eligible legacy scanners on primary reads, byte-exact role and PHP-range IDs. Forged private scanner IDs cannot be selected or validated. Preserve existing password/file/envelope conventions and named route contracts.
- Retain an old-code regression, verify controlled post-gateway/pretransaction changes, exact lock ordering/current membership queries, failure rollback/file compensation and private database-error feedback using isolated fixtures. Run focused and full backend tests, PHP formatting/syntax, unchanged 100 routes and 32-table safe CLI, source/environment/index/build preservation and affected docs.

Scope: one tenancy generation guard, package builder, shared offline trait, session store error catches, one new feature test and docs. No schema, ownership/account changes or configured data. This fences only package generation against writers following the Event/User protocol; caller snapshots, arbitrary external writers and dormant backfill still require their recorded fences. Postcommit download metadata/audits/response, later session/import/conflict/file operations, nested guest/scanner history references and guest-writer consistency remain separate tasks. No customer event/signup exposure or new actual MySQL concurrency claim.

Verification evidence:

The complete isolated backend suite passed **1,372 tests / 23,870 assertions** (246.062186s), with zero errors/failures/skips. Focused offline/staff checks passed **271 tests / 2,526 assertions** (49.817723s), including 48 new generation cases. Five scoped PHP syntax checks, final read-only Pint, all 100 unchanged route method/path pairs and the 32-table safe CLI passed. Exact source fingerprints, environment/app key, Git index, public/index.php, customer setup, dependencies/builds and all 401 captured baseline paths outside declared source/docs were preserved.

A retained old-code run reproduced private scanner identity exposure for both platform and inactive-member targets: 2 tests / 4 assertions / 2 failures / 0 errors. The fixed form and forged-input cases pass for master/client active/inactive memberships, platform flags, case-changed roles and noncanonical active/platform values. Eleven controlled pretransaction changes verify Event ownership, actor role/active/deletion, scanner role/active/assignment/platform/membership refusal with exact fixture conservation. Other checks cover valid legacy/platform actors, User read ordering before membership reads, exact near-PHP-max ordering, 18 ambiguous master/client IDs before row queries, outside-transaction guard refusal, and injected SQL failure after file write with complete rollback/cleanup and no private error/password feedback. Existing successful exports and all prior test classes retain their assertion counts.

The package builder now invokes one internal guard inside its existing transaction. Canonical raw Event/actor/master/client IDs are validated before row queries. The exact Event is locked first and must still be unowned. Deduplicated actor/master/client Users are locked by exact ascending integer ID. The actor must currently be an active byte-exact admin; each scanner must currently be an active byte-exact scanner assigned to that Event, have a false platform flag and have no workspace membership of any role/activity. Membership is reread with a current locking SELECT after the User locks, never a snapshot exists(). Invalid state refuses before snapshots, session/audit inserts, encryption or file writes.

The form choices and request existence checks share primary eligible-scanner predicates, including HEX role matching, PHP-range IDs, false platform flag and no membership row. Forged private master/client IDs fail existing field validation. A late Event ownership change produces 404; lost actor eligibility produces 403; the existing generic invalid-scanner business error remains compatible. Controller catches preserve these HTTP denials and return generic database-failure feedback with non-password input retained. A database failure after file creation rolls back generation rows and removes the newly created file through the preserved compensation path.

The existing transaction and retry boundary remains. Token comparison verifies that generation after the guard, exception/file compensation and crypto/payload/snapshot helper methods are unchanged apart from formatting/comments/optional trailing commas. Scanner input ordering and exported content conventions remain intact; only lock acquisition order is sorted. No new envelope, password/signature algorithm, schema or route is introduced.

Limits: This is source and controlled SQLite evidence. SQLite statements establish exact query/ID ordering and conservation, not actual MySQL blocking/current-read schedules; a selected real-engine follow-up remains next. The fence covers package generation against writers following the Event/User protocol. Existing caller transactions/snapshots, arbitrary external writers and dormant legacy backfill still require their recorded fences. Guest-writer/snapshot consistency, nested historical guest/scanner references, postcommit download metadata/audits/response, later session/import/conflict/file operations and public/static files remain separate boundaries. Actor eligibility here is current role/active state; credential/session revocation remains the earlier middleware observation. No account/owner/grant, configured data/schema/migration, environment/app key, service/dependency/build or deployment changed. Customer event/signup exposure remains closed. Browser/device/hosting/SMTP and actual database/ownership/restore/fencing remain unverified. Phase4 remains IN PROGRESS. [Details](LEGACY_OFFLINE_PACKAGE_GENERATION.md).

## Task P4-012 — COMPLETE (2026-10-23)

Status: COMPLETE (2026-10-23). Dependencies: P4-011 source gate and the owned-server lifecycle used by P4-008.

Objective: prove selected actual MySQL Event/User/membership waits, current eligibility and generation/file compensation using invented fixtures only.

Acceptance criteria:

- Use a new ACL-private loopback MySQL8.4.11 data directory on43496, verified signed read-only distribution and exact UUID/datadir/port/bind/version before writes. Apply frozen28 migrations to six nonce-scoped synthetic schemas; keep credentials private and all configured data/XAMPP/services unchanged.
- Verify six case groups: twelve serial raw-role/membership/flag/actual-unsigned identity observations; ownership change before generation; actor deactivation before generation; inactive membership grant after a caller RR absence snapshot; committed generation before ownership transfer; injected database failure after file creation with rollback/cleanup/retry.
- Use nine independent service workers and observe four exact WAITING PRIMARY RECORD row waits with both connection IDs: Event301 ownership, User211 actor, User221 membership and Event301 transfer. Refusals perform no generation DML/files; membership refusal must coexist with the caller's stale ordinary absence snapshot and preserve the committed grant.
- Exercise the production builder through a complete generation while its Event/User/membership locks are held. Verify exact expected12 DML statements, one session/two session users/one package/one guest snapshot/four audits, protocol/file hash and unrelated row/schema conservation. Later ownership transfer preserves that setup and retry refuses. Verify actual SQL-failure row rollback and new-file cleanup before successful retry.
- Preserve repository application/test source and the1,372-test /23,870-assertion gate by fingerprints; run TEMP PHP/PowerShell syntax/formatting, unchanged 100 routes/32-table read-only CLI and documentation checks. Stop owned workers/server, prove port free and safe unavailable2002, then remove all four generated plaintext credential files.

Scope: TEMP lifecycle/common/coordinator/worker verification code and task docs only. No production/controller/source/schema/dependency/build/environment/app-key or configured data change. No HTTP/scanner/device/browser/SMTP/hosting deployment or exhaustive schedules claim. Worker crash/orphan recovery, postcommit response/download metadata/audits and later offline/file/guest boundaries remain separate.

Verification evidence:

All **six actual MySQL 8.4.11 case groups / nine independent service workers** passed, including twelve serial observations and four exact row waits. Six fresh synthetic schemas each applied the frozen 28 repository migrations and contained 32 tables. The P4-011 **1,372 tests / 23,870 assertions** source gate is retained by exact application/test fingerprints; no new backend suite run is claimed for this TEMP/docs-only task.

| Case group | Actual engine evidence |
|---|---|
| Serial eligibility | Twelve observations cover eligible actor membership, active/inactive scanner memberships, noncanonical platform/active flags, byte-exact roles despite actual case/padding collation equivalence, distinct safe PHP-max/unsigned-overflow rows and owned Event refusal. Each guard call performs zero DML and conserves every row, table definition and file. Overflow scanner/actor inputs refuse before row queries. |
| Ownership first | Generation waits on Event PRIMARY 301. Transfer commits, generation returns 404 with no DML/files; only that Event ownership changes. |
| Actor deactivation first | Generation waits on User PRIMARY 211. Deactivation commits, generation returns 403 with no DML/files; only that actor active flag changes. |
| Membership after repeatable-read absence | A caller establishes an ordinary absence snapshot. An independent writer locks User 221 and commits an inactive owner membership. Generation waits on that User and its current locking membership read refuses while the caller's ordinary snapshot still reports absence. Nested then outer rollback preserves the committed grant and all other rows. |
| Generation before transfer | Production generation pauses after eligibility locks and before effects. Transfer waits on Event PRIMARY 301. Generation commits the exact 12 DML operations, one session, two session users, one package, one guest snapshot and four expected audits; its one encrypted file matches the recorded hash. Transfer then commits. A later retry returns 404 and preserves that setup/file. |
| Driver failure and retry | A real synthetic MySQL SIGNAL returns SQLSTATE 45000 / driver 1644 after the new file exists and before package INSERT. All four completed generation inserts roll back and the failed new file is removed. Retry commits the exact 12 operations and expected rows/file. No claim is made about preserving pre-existing files because the initial manifest is empty. |

The Oracle-signed read-only MySQL 8.4.11 distribution ran from a new ACL-private TEMP data directory on loopback 43496 with MySQLX disabled. UUID, datadir, port, bind address and version were checked before fixture writes. Performance Schema matched both worker connection IDs and the exact WAITING PRIMARY RECORD for each observed wait. Complete row snapshots and table definitions were compared; only AUTO_INCREMENT table options were normalized, so allocator restoration or ID reuse is not claimed. All owned workers/server are stopped, the port is free, unavailable driver 2002 was verified and all four generated plaintext credential files were removed. The configured Harusi database, XAMPP and other services/data were untouched.

All 404 captured task-start paths are conserved outside task documentation; one new guide is added. TEMP PHP/PowerShell syntax and formatting, unchanged 100 route method/path pairs, 32-table safe CLI, environment/app key, Git index and local documentation checks passed. The full backend suite was not repeated because application/test source is unchanged.

Limits: Selected synthetic MySQL evidence does not establish every schedule or actual customer rollout. These checks cover package-generation eligibility against cooperating Event/User writers, including one caller RR snapshot case. Arbitrary external writers, other caller states, guest-writer/snapshot consistency, nested historical references, worker crash/orphan recovery, postcommit response/download metadata/audits and later session/import/conflict/file/public-asset paths remain unverified. Credential/session revocation remains the earlier middleware observation. No source/test/schema/migration/API/UI/account/owner/grant/dependency/build/environment/app-key or real-data change or deployment occurred. Customer event/signup exposure remains closed. Actual database/ownership/restore/fencing and browser/device/hosting/SMTP remain rollout gates; Phase4 remains IN PROGRESS. [Details](MYSQL_PACKAGE_GENERATION_REHEARSAL.md).

## Task P4-013 — COMPLETE (2026-10-23)

Status: COMPLETE (2026-10-23). Dependencies: P4-010 entry checks and P4-011/P4-012 generation protocol.

Objective: fence existing administrator session cancellation/closing effects against current ownership, identity, parent and lifecycle changes.

Acceptance criteria:

- Add a scoped legacy session action service used only by the two existing HTTP controller actions. Validate canonical raw session, parent, selected-Event and actor IDs before row queries; derive actor from authentication and selected Event from server session. Lock the exact unowned Event, exact active byte-exact admin User, then exact Session on the primary connection; recheck raw parents under those locks. No platform/member bypass for owned Events.
- Recheck current action eligibility before effects. Cancel requires a blocking Session with no imported timestamp or current completed/partially imported result rows. Close requires imported results and a nonclosed/noncancelled Session with no current open conflicts. Lock immediate imports in ID order, verify their raw parent links, then read open conflicts per safe import ID with current locks; avoid snapshot exists() as the authoritative check.
- Preserve generic internal lock/session services, package generation/import transitions, lifecycle/audit conventions and routes. Delegate cancellation using locked current models; closing uses the existing transition on the locked current Session, avoiding an ordinary fresh() that could reread an old parent. All action/audit effects share the surrounding transaction/retry boundary.
- Late parent loss yields404; actor/current policy loss403; existing business errors remain generic compatible form errors. Catch database failures with safe cancel/close feedback and no SQL/binding leakage, after complete rollback. Preserve request validation and success redirects.
- Reproduce an old-code late-ownership failure, verify controlled late identity/parent/state changes, valid legacy/platform-member actors, exact row conservation, audit-failure rollback and locked ordering on isolated fixtures. Run focused/full backend tests, PHP syntax/formatting, unchanged 100 routes/32-table safe CLI, source/environment/index/build preservation and affected documentation.

Scope: one new legacy session action service, existing session controller cancel/close wiring, one new feature test and docs. No schema/account/ownership/configured-data changes. Current checks protect these administrator actions against cooperating Event/User/Session writers; cross-import/resolver lock inversions retain bounded failure/rollback rather than a global deadlock-free claim. Actual engine waits for this service, arbitrary external writers, other offline/file/postcommit paths, nested history and guest consistency remain separate. Customer event/signup remain closed.

Verification evidence:

The complete isolated backend suite passed **1,482 tests / 24,782 assertions** (1329.508001s), with zero errors/failures/skips. Focused offline/staff checks passed **381 tests / 3,438 assertions** (81.997830s), including 110 new action cases. Three scoped PHP syntax checks, final read-only Pint, unchanged 100 route method/path pairs, 32-table safe CLI, environment/app key, Git index and local documentation checks passed. All 405 captured baseline paths outside the declared controller/docs scope are preserved; the 329 tested non-documentation paths match the final gate.

The two existing administrator cancel/close actions now enter a dedicated legacy action service after their existing entry checks, Gate and form validation. Canonical raw Session/parent/selected-Event IDs and equal original/current actor IDs are checked before row queries. Within the surrounding transaction it locks the exact unowned Event, active byte-exact admin User and exact Session on the primary connection, then verifies raw parent identity again. A platform flag or workspace membership never bypasses Event ownership. Eligible legacy/platform-member admins can still act on unowned legacy Sessions.

Cancellation rechecks the current blocking state and absence of an imported timestamp, then uses current locking reads to refuse completed or partially imported result rows. Closing rechecks current imported eligibility and nonclosed/noncancelled state, then reads current open conflicts under locks. Immediate imports are locked in ID order and their canonical raw IDs/session/Event links are verified before safe import IDs are used for per-import conflict reads. No joined optimizer-dependent child lock order or snapshot exists() is used as the new authoritative check.

Cancellation delegates to the existing lock service using the locked current Session/actor. Closing invokes the existing Session transition directly with the locked current Session, avoiding releaseLock()->fresh() rereading an old caller-snapshot parent. Generic internal lock/Session/import services and package generation remain byte-preserved. Existing success redirects, cancellation reason trimming, status/timestamp changes and audit action conventions remain intact; there are still exactly 100 routes and 32 inventory tables.

Late missing/owned/changed parent state returns 404; lost actor or current action policy returns 403. Imported cancellation/open-conflict business refusals retain their existing form messages. QueryException and Laravel nested DeadlockException receive generic cancel/close feedback after transaction handling, with no SQL/binding details. Session changes and audits share the enclosing transaction/retry boundary; an audit-write failure rolls back the Session update and first audit.

The new cases cover 44 late ownership/actor/Session changes across staff/platform-member actors, ten current-policy losses, six late imported-result rows, two late open conflicts, six successful audit timelines, four second-audit SQL failures, four form validation cases, 24 ambiguous raw IDs, four original/current unsafe actor aliases, four immediate-import parent failures and two synthetic typed deadlock faults before the Session update. Full fixture row/file conservation and initial Event301/User211/Session401 query order are checked. The deadlock faults establish generic feedback and zero completed DML before the fault, not actual MySQL deadlock recovery.

A retained old-code run reproduced all four late-owned-Event denial failures (cancel/close, staff/platform): 4 tests / 4 assertions / 4 failures / 0 errors, receiving 302 where 404 was required. The first attempted red run had a test-only explicit Guest-ID/FK setup error and is retained separately. The first focused run exposed the unsafe-original/current-safe actor alias, which was fixed by requiring canonical equal IDs, plus two test-only actor-deletion fixtures blocked by restrictive membership FKs; those fixtures now remove the membership before the controlled deletion. The final focused/full gates pass; failures were not hidden or reclassified as engine evidence.

Limits: This is source and controlled SQLite evidence. Query ordering and conservation do not establish actual MySQL waits, every schedule or the configured customer database. These two HTTP actions are fenced against cooperating Event/User/Session writers. Importer/resolver lock inversions still exist; bounded retries and generic failure/rollback do not establish a globally deadlock-free graph. Credential/session revocation remains the earlier middleware observation. Immediate import parent checks do not validate every historical guest/scanner/log/conflict reference. Other import/conflict/file operations, generation postcommit download metadata/audits/response, arbitrary external writers, guest consistency and public/static assets remain separate. No schema/account/owner/grant/dependency/build/environment/app-key, configured data/service or deployment changed. Customer event/signup exposure remains closed; actual database/ownership/restore/fencing and browser/device/hosting/SMTP remain rollout gates. Phase4 remains IN PROGRESS. [Details](LEGACY_OFFLINE_SESSION_ACTIONS.md).

## Task P4-014 — COMPLETE (2026-10-23)

Status: COMPLETE (2026-10-23). Dependencies: P4-010 entry checks, P4-011/P4-012 generation and P4-013 current identity protocol.

Objective: fence package download metadata, audit and buffered response construction through current legacy Event/actor/Session/Package checks, including delivery after a separately committed generation.

Acceptance criteria:

- Add one delivery service shared by immediate Session store, current-export generate/download and historical package download paths. Validate canonical raw Package/Session/selected-Event IDs, optional current-export Session identity and equal original/current safe actor IDs before row queries. Derive actor/selected Event from server state.
- Lock exact selected unowned Event, active byte-exact admin User, captured Session and exact Package on the primary connection. Recheck every raw parent under locks. When the optional current-export Session is supplied, require its current raw package_export_id to equal that Package; historical package URLs retain history access without that pointer requirement. No platform/member ownership bypass.
- Recheck current revoked/deleted/null-path policy before file access or DML. Preserve historical archived/expired/closed/cancelled behavior and existing file-path/private-disk/envelope/name/header conventions. Build the existing fully buffered Response under locks before recording metadata/audit; return only after transaction commit. No lazy file stream or fresh() snapshot reread.
- Keep builder commit distinct. A delivery failure never rolls back, regenerates or deletes the already-generated setup/file; retry uses the existing package. Metadata/audit/storage failures conserve delivery rows/files. Propagate current HTTP403/404 denials; other failures return generic 503 without SQL/path/secret leakage. Existing successful metadata/audit conventions and exactly100 routes remain.
- Reproduce an old-code non-atomic delivery failure; verify late current/historical authority/parent/policy changes, safe raw identities, success bytes/headers and transaction query order, buffered response behavior, SQL/audit/deadlock/storage faults and real generation followed by delivery failure/retry on isolated fixtures. Run focused/full backend regression, PHP syntax/formatting,32-table safe CLI, source/environment/index/build preservation and affected docs.

Scope: one delivery service, two existing controllers and one new feature test plus docs. FileStorage, builder/crypto/package formats, archive/secure-delete writers, schema and configured data remain unchanged. This fences row authorization and buffered bytes against cooperating database writers; existing file deletion removes bytes before taking database locks and is not serialized by this service. No new file-hash/path-ownership integrity guarantee, client receipt/retraction, all-schedules/actual MySQL delivery wait or customer rollout claim. Other offline/import/conflict/file/guest boundaries and customer event/signup remain separate.

Verification evidence:

The complete isolated backend suite passed **1,565 tests / 25,642 assertions** (290.592614s), with zero errors/failures/skips. Focused offline/staff checks passed **464 tests / 4,298 assertions** (45.800477s), including 83 new delivery cases. Four scoped PHP syntax checks, final read-only Pint, safe route/CLI checks, environment/app key, Git index and documentation links passed. All 408 baseline paths outside the two declared controllers/docs remain preserved; final tested fingerprints cover 331 non-documentation paths.

All three existing package delivery paths now share one service: immediate download after generation, current Session export download and historical Package download. Canonical raw Package/Session/selected-Event identities and equal safe original/current actor IDs are validated before row queries. Within the delivery transaction it locks the exact selected unowned Event, current active byte-exact admin User, captured Session and exact Package on the primary connection, then verifies every raw parent again. Optional current-export Session input also requires its fresh package_export_id to equal the delivered Package. Historical URLs preserve access to older packages without that pointer requirement. Platform/member admins gain no owned-Event bypass.

Current revoked/deleted/null-path policy is rechecked before file access or DML. The existing FileStorage helper reads the complete file and builds an ordinary buffered Response while locks are held. Delivery then updates downloaded metadata and records the existing audit with current models, and returns only after the transaction call succeeds. Existing bytes, private disk/path checks, filename sanitation, headers and historical archived/expired/closed/cancelled behavior are retained. There is no lazy stream, ordinary fresh() parent reread, new hash verification or package format change.

Generation and delivery remain separate commits. Delivery runs outside generation error handling. If current authority or a precommit storage/SQL/audit fault prevents delivery, the already-generated Session/Package/snapshot/audits/file remain intact, with no automatic regeneration or deletion. Retry uses the existing Package. Missing/changed parents return 404, current actor/policy losses 403; other failures return a private plaintext 503 response with visible retry guidance and no SQL, binding, path or secret details. Precommit delivery faults roll back its metadata/audit changes. A generic 503 does not prove rollback for uncertain commit outcomes or exceptions from after-commit observers.

The builder/crypto/FileStorage/audit and generic Session/lock/import services are byte-preserved. Exact comparison also preserves Session create/show/cancel/close methods, store generation checks/catches and Package archive/delete methods. Exactly 100 route method/path pairs and 32 inventory tables remain. No dependencies, builds, configured environment/app key, accounts, ownership or real data/services are changed.

Checks cover late current/historical authority, parent and package policy changes, current-export pointer changes, byte-exact actor role, malformed/unsafe identities, byte/header and historical compatibility, explicit initial Event/User/Session/Package query order, fully buffered response behavior, precommit metadata/audit/typed-deadlock/storage faults and real generation followed by refused/failed delivery and retry through the existing Package. Fixture rows and private file manifests are compared. SQLite query order and synthetic faults do not establish real MySQL blocking or client receipt.

The retained old-code regression targets non-atomic delivery: the Package metadata UPDATE completes, a controlled audit INSERT fails and the metadata remains changed. The corrected service rolls back that precommit partial write. Late authority checks are exercised separately at transaction entry in the fixed source; the old paths had no delivery transaction, so an unused beforeStartingTransaction hook is not presented as old-code authority-race evidence. The first red report omitted the current-export confirmation and is retained separately; the corrected two-case report reproduces partial metadata on both paths. The first focused run also found that the standard Laravel 503 page hid retry guidance, so the service now returns private plaintext 503 feedback. A test-only null-path mutation violated the existing NOT NULL schema; it was replaced with an FK-valid missing-Package change. The final focused/full gates pass.

Limits: This fences current row authorization and buffered bytes against cooperating database writers. Existing archive/delete writers remain outside this protocol; secure deletion removes filesystem bytes before updating its row, so database locks here do not serialize that removal or restore deleted bytes. Existing safe relative/private-disk rules do not independently establish historical path ownership or bytes/hash integrity after external corruption/replacement. Buffering is not proof of client receipt, and committed bytes cannot be retracted after later ownership/revocation. Commit uncertainty and after-commit observer errors remain separate. Actual MySQL delivery schedules, other caller states, arbitrary external writers, nested historical references, guest consistency and later import/conflict/file/public-asset actions remain unverified. No customer event/signup exposure or deployment occurred. Configured database/ownership/restore/fencing and browser/device/hosting/SMTP remain rollout gates; Phase4 remains IN PROGRESS. [Details](LEGACY_OFFLINE_PACKAGE_DELIVERY.md).

## Task P4-015 — COMPLETE (2026-10-24)

Status: COMPLETE (2026-10-24). Dependencies: P4-010 entry parents and P4-013/P4-014 current action identity protocol.

Objective: fence the two existing administrator Package/ResultImport archive metadata updates and audits without moving, reading or deleting file bytes.

Acceptance criteria:

- Add one scoped archive action service for Package/ResultImport. Validate canonical raw resource/Session/selected-Event IDs, ResultImport Event links and equal safe original/current actor IDs before queries. Lock exact selected unowned Event, active byte-exact admin User, captured Session and exact resource on primary; verify current raw parent links without following a changed parent. No platform/member bypass.
- Recheck only existing archive policy: archived_at and secure_deleted_at must remain null. Preserve revoked Package, terminal/expired Session, ResultImport status, missing bytes/path and disabled secure-deletion compatibility. Repeated/lost archive eligibility403; bad/missing/owned/changed parents404 before effects.
- Run metadata update and existing file_archived audit inside the surrounding transaction/retry boundary. Add an optional current Session parameter only to the two existing FileStorage archive helpers so the wrapper can supply its locked audit parent, avoiding an ordinary caller-RR relation reread. Preserve helper update/audit bodies and legacy three-argument behavior; the optional model is not an authorization token.
- Preserve existing form validation, success messages/redirects and archive reasons. HTTP denials propagate. Other failures use generic package/results form feedback, retain only archive_reason input and never show SQL/bindings/private details. Prove precommit failure rollback; do not claim certainty after unknown commit/observer failures.
- Reproduce existing non-atomic audit failure; verify late identity/parent/policy changes, both resource types, exact metadata/audit compatibility, no file IO, raw identities, SQL/deadlock faults and full row/file conservation on isolated fixtures. Run focused/full backend tests, scoped PHP syntax/formatting, unchanged 100 routes/32-table safe CLI, source/environment/index/build preservation and affected docs.

Scope: one archive action service, two existing archive controller methods, two FileStorage archive helper signatures/parent selection, one feature test and docs. No schema/account/ownership/configured-data changes. Download, generation, session/import/conflict/secure-delete behavior and other helper bodies remain preserved. Archive is existing metadata only, not byte relocation. Import/resolver lock inversions, actual MySQL archive schedules, arbitrary external writers, commit uncertainty, nested historical/file integrity, remaining file/guest boundaries and customer event/signup remain separate.

Verification evidence:

The complete isolated backend suite passed **1,653 tests / 26,523 assertions** (240.913817s), with zero errors/failures/skips. Focused offline/staff checks passed **552 tests / 5,179 assertions** (1674.227407s), including 88 new archive cases. Five scoped PHP syntax checks, final read-only Pint, unchanged 100 routes, 32-table safe CLI, exact source/environment/app-key/Git-index preservation and documentation links passed. All 411 baseline paths outside the three declared source files/docs remain preserved; final fingerprints cover 333 non-documentation paths.

The two existing Package/ResultImport archive actions now use a scoped metadata service after their existing entry checks, Gate and form validation. It validates canonical raw resource/Session/selected-Event IDs, ResultImport Event links and equal safe original/current actor IDs before queries. Inside its transaction, exact selected unowned Event, active byte-exact admin User, captured Session and exact resource are locked on primary. Current raw parents and archive eligibility are rechecked before effects. A changed parent is refused without following another Session; platform/member admins receive no owned-Event bypass.

Archive still means existing archived_at/by metadata plus one existing file_archived audit. It does not move, open or remove file bytes. Eligibility remains exactly the existing archive policy: archived_at and secure_deleted_at are null. Revoked Packages, terminal/expired Sessions, ResultImport workflow status, absent file bytes/path and disabled secure deletion retain their previous archive compatibility. Repeated/lost archive eligibility returns 403; bad/missing/owned/changed parents return 404. Existing reasons, validation, success messages and redirects remain.

The two FileStorage archive helpers accept an optional fourth current Session argument. The guarded wrapper supplies its locked parent for the audit, avoiding an ordinary relation read from an older caller RR snapshot. Legacy three-argument calls retain the existing fallback. This optional model is not an authorization token: callers still need the current action/resource checks. Token comparison preserves all helper code except those parameter/parent-selection additions, ignoring formatting/comments/optional trailing commas. Controller entry validation/success and all unrelated methods remain preserved.

Archive metadata and audit share the surrounding transaction/retry boundary. HTTP refusals propagate. Other failures return generic package/results form errors, retain only archive_reason and show no SQL/binding/private details. Controlled precommit SQL/audit faults roll back the completed update; synthetic typed deadlock faults before mutation verify generic feedback and zero completed DML, not actual MySQL deadlock recovery. Uncertain commit outcomes and after-commit observer failures are not a rollback guarantee.

Checks cover both resource types and eligible legacy/platform-member actors, late Event/actor/Session/resource parent and archive policy changes, raw/unsafe identities, exact metadata/audit compatibility, current parent delegation and initial Event/User/Session/resource query order. Form failures and SQL/audit/deadlock faults preserve the compared rows/files. A storage spy rejects put/get/exists/delete calls during archive actions, and private neighbor payload/history hashes remain conserved. Compatible non-downloadable/historical states are covered independently.

The retained old-code regression injects audit failure after an archive metadata UPDATE and compares fixture state before response feedback. It reproduces non-atomic partial archive metadata; the fixed service rolls back that precommit update. Late transaction-entry mutations are fixed-source checks, not a claim that old archive code had a transaction hook. The first full run retained one 30-second recovery-script subprocess timeout (1,653 tests / 26,517 assertions, one error). Its separate recovery-class recheck passed all 33 cases / 698 assertions with the unchanged timeout, followed by the clean final full gate above. Both reports are retained; the earlier error is not treated as a pass.

Limits: This is controlled SQLite source/conservation evidence, not actual MySQL waits or every concurrency schedule. Cooperating Event/User/Session/resource writers are assumed; the importer/resolver lock inversion remains, so bounded retries/generic failure do not establish a globally deadlock-free graph. Credential/session revocation remains the earlier middleware observation. Generic three-argument helpers and the optional parent argument do not independently authorize a caller. Archive metadata is not backup, byte relocation, client delivery or deletion. Secure deletion still removes bytes outside this protocol and needs its own file/SQL failure design. Other import/conflict/result/file/public-asset paths, nested history, path/hash integrity, arbitrary external writers and guest consistency remain separate. No schema/account/owner/grant/dependency/build/environment/app-key, configured data/service change or deployment occurred. Customer event/signup remain closed; actual database/ownership/restore/fencing and browser/device/hosting/SMTP are rollout gates. Phase4 remains IN PROGRESS. [Details](LEGACY_OFFLINE_FILE_ARCHIVES.md).

## Task P4-016 — COMPLETE (2026-10-24)

Objective: make direct legacy package-generation calls reject inconsistent original/current actor identities before any row queries.

Acceptance criteria:

- Require canonical positive safe raw original and current actor IDs to be equal before scanner parsing or row queries, matching the current identity protocol of the session, delivery and archive actions. Refuse padded, overflowing or retargeted originals with403; preserve equal canonical string/integer compatibility.
- Preserve the existing transaction requirement, exact current actor policy, Event/sorted-User/membership lock order, scanner checks, builder, encryption, files and all successful HTTP behavior. This is a direct service input contract; no demonstrated HTTP exploit is claimed.
- Reproduce all three mismatches against the old guard. Add four isolated feature cases checking zero row queries/DML on refusal, complete fixture conservation, outer transaction preservation and the valid canonical control; preserve every existing test body.
- Run focused/full backend regression, scoped syntax/formatting and unchanged 100-route/32-table safe CLI checks. Verify source, environment/app key, Git index/build and documentation links; update task evidence and limits.

Scope: one existing guard condition, four cases in the existing generation test and documentation. No schema, configured data/service, ownership, dependency, file-format or rollout changes. Actual engine schedules, arbitrary external writers, remaining offline/file/import/conflict and nested history boundaries remain separate. Phase4 remains IN PROGRESS.

Verification checkpoint (2026-10-24): focused 556 tests / 5,210 assertions passed. The first full run completed 1,657 tests / 26,549 assertions with one existing recovery-script child exceeding 30 seconds (mixed testing apply), no assertion failures. The failed report is retained; source/timeouts are unchanged. At this checkpoint P4-016 remained IN PROGRESS pending recheck and a clean full gate.

Recovery follow-up (2026-10-24): the isolated recovery class passed 33 tests / 698 assertions with the original 30-second child deadline. A second unchanged-source full run is in progress; the first failure report remains retained. Artifact review shows delayed fresh Laravel cache/bootstrap progress; the specific host cause is not established.

Verification evidence:

The isolated full backend suite passed **1,657 tests / 26,554 assertions** (311.314621s), zero errors/failures/skips. Focused offline/staff checks passed **556 tests / 5,210 assertions** (120.298271s). Four added generation cases contribute 31 assertions: three prequery identity refusals with exact fixture conservation/outer transaction checks and one canonical control. The generation class now has 52 cases / 233 assertions; all 71 previous classes retain their earlier counts/assertions except this intentional 4-case/31-assertion addition. The old guard reproduced all three refusals as 3 failures / 0 errors / 9 assertions. The first full run retained one 30-second recovery-script subprocess timeout (1,657 tests / 26,549 assertions, one error); its unchanged-timeout recovery-class recheck passed 33 cases / 698 assertions before the clean final full gate above. Failed and clean reports are retained separately. Existing test bodies are byte-preserved after newline normalization, allowing the one Pint-added exception import and the four new cases; the guard differs only by the equality predicate. Two scoped syntax checks and read-only Pint passed, along with unchanged 100 routes, 32-table safe CLI, 333 tested source fingerprints, 414 baseline paths outside declared guard/test/docs changes, environment/app key, Git index/build and documentation links.

Direct legacy package-generation checks now require equal canonical safe original/current actor IDs before scanner parsing or row queries. Overflowing or padded originals and a current ID retargeted to another real active admin return 403, with no row queries or DML. Equal canonical string/integer representations remain accepted. The existing Event/sorted-User/current-membership protocol, transaction requirement, scanner policy, builder, encryption, export and file compensation are unchanged.

Limits: These are direct service input regressions using controlled SQLite fixtures, not a demonstrated HTTP exploit or new MySQL schedule evidence. Current middleware authority remains its earlier observation; arbitrary external writers and every engine/concurrency schedule are not established. Remaining secure-delete/file, result-preview/import/conflict, nested history/path/hash and guest boundaries remain separate. No schema/account/ownership/dependency/build/environment/app-key or configured data/service change or deployment occurred. Automatic empty-workspace creator ownership remains implemented; customer event/signup exposure and actual database/restore/legacy ownership/fencing/browser/device/hosting/SMTP remain rollout gates. Phase4 remains IN PROGRESS. [Generation details](LEGACY_OFFLINE_PACKAGE_GENERATION.md).

## Task P4-017 — COMPLETE (2026-10-24)

Objective: stop existing secure-delete actions from recording deletion success when the storage helper returns false for an attempted existing-file deletion.

Acceptance criteria:

- In both Package and ResultImport helpers, require a successful boolean delete result before deletion metadata or audit writes. A false result throws a stable generic RuntimeException handled by the existing controller form feedback; no success is reported.
- Preserve existing retention configuration, private disk/path validation, missing-file/null-path compatibility, metadata/audit fields, form validation, routes and history eligibility. Successful or already-missing file behavior remains unchanged.
- Reproduce false-return failures for both existing HTTP actions using invented private fixture files. Verify exact row/file conservation and zero completed DML when the false-return fixture retains bytes; add success, missing-file, nullable results path/disk fallback and disabled-configuration controls.
- Run scoped syntax/formatting, focused/full backend regression, unchanged 100 routes/32-table safe CLI, tested-source/environment/app-key/Git-index/build preservation and documentation links. Record observed evidence and limits.

Scope: two delete-result checks in the existing FileStorage helper, one feature test and docs. This handles a reported boolean failure only. It does not establish current authority row fencing, restoration after SQL/audit failure following physical deletion, partial adapter failure, crash recovery, secure physical erasure or a distributed file/SQL transaction. Those remain separate work. No configured data/service, schema, ownership, dependency, file format or deployment changes.

Verification evidence:

The isolated full backend suite passed **1,666 tests / 26,689 assertions** (168.562228s), zero errors/failures/skips. Focused offline/staff checks passed **565 tests / 5,345 assertions** (101.365055s). The new nine-case class contributes 135 assertions, covering both false-result HTTP actions, real fixture-file deletion success, missing bytes, nullable results path/default-disk fallback and disabled policy. The false-return storage fixture leaves bytes untouched; exact table rows/private file hashes, existing audits, neighbor data and zero completed DML are checked before error feedback. Success controls preserve history eligibility for revoked/archived historical Packages, archived failed Imports and closed Sessions with past package expiry times. The retained old-code regression reproduces both false certifications as 2 failures / 0 errors / 6 assertions. All 71 prior classes retain exact counts/assertions. Two scoped syntax checks and read-only Pint passed, along with unchanged 100 routes, 32-table safe CLI, 334 tested source fingerprints, 414 baseline paths outside the declared source/docs scope, environment/app key, Git index/build and documentation links. Exact normalized source comparison allows only the two delete-result checks; existing controller/archive/delivery/generation behavior is preserved.

Both existing Package/ResultImport secure-delete helpers now require a successful boolean delete result before writing deletion metadata or the file_deleted audit. If an attempted existing-file deletion returns false, a stable generic error reaches the existing form feedback and no success is reported. The existing retention configuration, path/private-disk checks, validation, metadata/audit fields and routes remain unchanged. Already-missing bytes and a nullable results path retain their previous metadata/audit behavior; no file path is inferred.

Limits: This fixes handling of the reported boolean result. The false fixture conserves bytes by construction; an arbitrary adapter may partially act before returning false. A true return is not proof of physical secure erasure. Missing-file success retains its existing metadata-only meaning. Current Event/actor/Session/resource row fencing, SQL/audit failure after physical deletion, uncertain commit, file restoration, crash recovery and a distributed file/SQL transaction remain separate work. SQLite fixture checks do not establish actual MySQL waits, every schedule, actual customer data or arbitrary external writers. Result preview/import/conflict, nested history/path/hash integrity, guest boundaries and customer event/signup exposure remain open. No schema/account/ownership/dependency/build/environment/app-key or configured data/service change or deployment occurred. Automatic creator ownership for new empty workspaces remains implemented; actual database/restore/legacy ownership/fencing/browser/device/hosting/SMTP remain rollout gates. Phase4 remains IN PROGRESS. [Offline workflow](OFFLINE_SYNC.md).

## Task P4-018 — COMPLETE (2026-10-25)

Objective: prevent existing secure-delete form errors from exposing SQL, database bindings or private storage details.

Acceptance criteria:

- Replace raw RuntimeException messages in the two existing Package/ResultImport destroy catches with stable generic feedback. Keep the same catch types, error keys, validation, redirects and success messages. Preserve P4-017 false-result feedback exactly.
- Reproduce the raw disclosure using real isolated SQLite resource-UPDATE and audit-INSERT failures for both resource types. Add existing-file storage exists/delete failures carrying invented private markers. Verify safe feedback, no success message and exact compared rows/private file hashes.
- Use a nontransactional isolated test harness for new cases so partial effects are observed honestly: bytes can be gone before SQL failure; an audit failure can leave completed metadata without its audit. Runtime storage faults before deletion conserve rows/files. Do not describe feedback sanitation as atomic deletion, authority fencing, restoration or physical secure erasure.
- Preserve every existing test, helper, route, configured setting, file format and successful/missing-file behavior. Run focused/full regression, scoped PHP syntax/formatting,100-route/32-table safe CLI, source/environment/app-key/Git-index/build and documentation checks.

Scope: the two existing error expressions and removal of their unused catch variables, one new feature test and docs. No new transaction/lock/retry, storage or audit behavior, application schema, account, ownership, dependency or configured data/service change. The TEMP-only current-authority deletion design is still unimplemented; ownership fencing, file/SQL recovery, uncertain commit, nested history/path integrity and customer exposure remain separate gates.

Verification checkpoint (2026-10-25): the corrected nontransactional in-memory harness passed 573 focused tests / 5,563 assertions, with zero errors/failures/skips. The original controllers reproduced all 4 SQL disclosures / 62 assertions using proven original-file autoloading, without reverting current application source. The initial 8 teardown errors from an existing unsupported SQLite down migration are retained separately; no migration guard or application migration changed. At this checkpoint full regression was pending; P4-018 was IN PROGRESS.

Verification evidence:

The complete isolated backend suite passed **1,674 tests / 26,907 assertions** (2998.693118s), zero errors/failures/skips. Focused offline/staff checks passed **573 tests / 5,563 assertions** (59.024930s). The new eight-case class contributes 218 assertions: four real SQLite TEMP-trigger failures on resource UPDATE/audit INSERT, plus four controlled pre-effect exists/delete storage exceptions. The retained old-code run has 4 cases / 62 assertions / 4 failures / 0 errors, each exposing the injected SQL/private marker only after its state observations passed. All 72 prior test classes retain exact counts/assertions. Three scoped PHP syntax checks and read-only Pint, unchanged 100 routes/32-table safe CLI, 335 tested source fingerprints, 415 baseline paths outside declared controller/docs changes, environment/app key, Git index/build and local documentation links passed. Normalized whole-controller comparison permits only the two destroy feedback blocks; all other methods, catches and helper/service code are preserved.

Both existing secure-delete controller catches now return the same stable generic file-deletion error instead of RuntimeException::getMessage(). SQL statements, interpolated bindings, driver messages and private storage paths no longer reach these flash errors. Catch types, error keys, validation, redirects and success messages are preserved; unused catch variables are removed. The separate result-confirm business-error catch is unchanged. P4-017 false-return feedback remains identical.

The new cases create a fresh guarded in-memory SQLite schema per test, discard the captured owned connection afterward and prove Laravel level 0/PDO inactive before and after the action. A failed deletion-metadata UPDATE occurs after bytes are gone and leaves rows unchanged; a later audit INSERT failure leaves completed deletion metadata but no new audit. These are observed existing autocommit partial outcomes, not improvements to recovery. The tested storage exceptions occur before bytes change and conserve every compared row/file. Full table snapshots, owned-neighbor history and private fake-disk hashes are checked before feedback assertions. The first focused run retained eight teardown errors from an existing SQLite-incompatible named-foreign-key rollback migration. Its application assertions passed, but it is not a clean gate. The repaired harness discards only its private in-memory database and leaves application migrations, rollback guards and global RefreshDatabaseState unchanged; it does not verify down migrations. The final old-code run loads only the two raw original controllers through a dedicated test bootstrap, with exact reflection-path/hash provenance, and leaves current application files unchanged.

Limits: This is feedback sanitation inside the two existing RuntimeException catches. Binding/preflight/validation failures outside those blocks and other importer/resolver feedback remain separate. No transaction, retry, lock, file/audit order, deletion eligibility, retention configuration, file format or helper behavior changed. Generic feedback does not restore bytes, undo partial metadata, prove physical secure erasure or settle unknown commit outcomes. Current authority fencing and its transaction-lifecycle/recovery design remain unimplemented. SQLite triggers and controlled storage faults are not real MySQL deadlocks, network failures, crash recovery or arbitrary-adapter guarantees. No schema/account/ownership/dependency/build/environment/app-key or configured data/service change or deployment occurred. Customer event/signup, actual database/restore/legacy ownership/fencing and browser/device/hosting/SMTP remain rollout gates. Phase4 remains IN PROGRESS. [Offline workflow](OFFLINE_SYNC.md).

## Task P4-019 — COMPLETE (2026-10-25)

Objective: prevent PDO-class failures during existing offline result confirmation and conflict resolution from exposing SQL or private bindings through business-error feedback.

Acceptance criteria:

- Add a narrow PDOException catch after the existing ValidationException catch and before each business-error union. Use stable action-specific generic messages for result confirmation and conflict resolution; preserve every existing business/validation message, error key, success response and outside-block HTTP refusal.
- Reproduce four real SQLite resource-write/audit failures with legitimate encrypted preview/conflict fixtures. Verify completed mutations followed by precommit savepoint rollback to exact compared rows/private file hashes before checking safe feedback.
- Verify direct PDO/typed deadlock feedback controls without completed DML, and preserve both actions’ InvalidArgumentException business messages. Typed injected faults are feedback evidence, not actual MySQL deadlock recovery.
- Preserve services, transaction/retry/lock/file behavior, existing tests and all routes. Run focused/full regression, scoped syntax/formatting,100-route/32-table safe CLI, source/environment/app-key/Git-index/build and documentation checks.

Scope: two existing controller catch insertions/PDO imports, one new feature test and docs. Test rollback evidence is controlled SQLite level 2→1 within a caller transaction, not outer-commit/network certainty or all engine schedules. Binding/preflight/request-validation failures outside the service-call try blocks remain framework-handled. Current authority/file recovery, nested history/path integrity and customer exposure remain separate gates. No schema/account/ownership/dependency/configured data/service or deployment changes.

Verification evidence:

The complete isolated backend suite passed **1,684 tests / 27,184 assertions** (280.096104s), zero errors/failures/skips. Focused offline/staff checks passed **583 tests / 5,840 assertions** (89.289556s). The new 10-case class contributes 277 assertions. Four old-code SQL disclosure cases passed their state observations before failing feedback assertions: 4 tests / 70 assertions / 4 failures / 0 errors. All 73 prior classes retain exact counts/assertions. Three scoped syntax checks and read-only Pint, unchanged 100 routes/32-table safe CLI, 336 tested source fingerprints, 416 baseline paths outside the declared two controllers/docs, environment/app key, Git index/build and local links passed. Normalized whole-controller comparison permits only the PDO import and catch insertions; service/transaction/file and all existing test code remain preserved.

The result-confirm and conflict-resolve service-call blocks now catch PDOException before their existing business-error unions. Local QueryException, direct PDO and typed DeadlockException failures receive stable action-specific form errors, keeping driver/SQL/binding/private-path details out of those errors. ValidationException handling, existing InvalidArgumentException/RuntimeException/LogicException business messages, error keys, redirects and successes remain unchanged. P4-018 deletion feedback is preserved.

The four real SQLite TEMP-trigger cases use legitimate encrypted preview and drift-conflict fixtures: first Import status UPDATE, final import audit INSERT, conflict-resolution UPDATE after admission mutations, and conflict audit INSERT. Every compared table row and private fake-disk file hash is checked before feedback. Failures restore the caller state through existing level 2→1 savepoint rollback. The later three faults observe completed mutations before rollback. Four direct PDO/typed-deadlock faults occur before completed DML and verify safe feedback only; two InvalidArgumentException controls retain their business messages and conserve compared state.

Limits: These changes sanitize PDO-class failures escaping the two existing service calls. Binding, preflight and request-validation SQL failures outside those blocks remain framework-handled; business RuntimeException messages are deliberately preserved. Controlled SQLite savepoints do not establish outer-commit certainty, real MySQL deadlock recovery, network/crash outcomes, every concurrency schedule or a globally deadlock-free graph. No import/resolver transaction, retry, lock, file, policy or audit behavior changed. Current authority fences, preliminary preview audits, nested history/path integrity, secure-delete recovery and unknown commit outcomes remain separate. No schema/account/ownership/dependency/build/environment/app-key or configured data/service change or deployment occurred. Customer event/signup, actual database/restore/legacy ownership/fencing and browser/device/hosting/SMTP remain rollout gates. Phase4 remains IN PROGRESS. [Offline workflow](OFFLINE_SYNC.md).

## Task P4-020 — COMPLETE (2026-10-26; opened 2026-10-25)

Objective: prevent result-preview Guest references from copying another Event’s current Guest details or aliasing unsafe external IDs to a real PHP-range Guest.

Acceptance criteria:

- Use one canonical positive integer parser for the affected preview/review Guest references: accept positive integers and exact decimal strings within PHP_INT_MAX; reject overflow, padding, trailing newlines and other ambiguous representations before numeric Guest queries. Validate the locked Session’s raw Event identity before preview record/file creation.
- Restrict preview Guest batch loading to the current locked Session Event. For identifiers already present in that Session’s package snapshots, retain WRONG_EVENT classification using only a redacted id-only existence distinction; do not pass a foreign Guest model/current fields into conflict/final-state records. Preserve legitimate same-Event missing-snapshot context.
- Apply strict parsing to review log Guest lookup and numeric Guest search, and use the raw external key in the log table so an overflow/newline row cannot borrow a canonical MAX Guest already present on the same page. Preserve legitimate canonical-MAX display and manual-code/name text search.
- Reproduce foreign unowned/owned Guest detail copies and unsafe-ID aliases through legitimate signed encrypted uploads; verify moved-snapshot classification, null foreign current Guest bindings/state, rendered log-row behavior, and neighbor row/file conservation. Preserve every existing case/assertion contract.
- Run scoped PHP/Blade syntax and formatting, focused/full regression,100-route/32-table safe CLI, source/environment/app-key/Git-index/build and documentation checks.

Scope: a shared positive-ID helper, existing preview Guest load/classification/ID parsing, review Guest lookup/search, one log-table key expression, one feature test and docs. No schema, transaction/retry/lock, crypto, audit/file-order or configured data/service change. This is preview Guest namespace/ID evidence, not complete tenant isolation. Current parent/actor authority, preliminary audits, old stored/current Guest relations in other review paths, confirmation/resolver Guest handling, concurrency/guest movement, key/session-user recertification and file/commit recovery remain separate gates. Customer event/signup remain closed.

Verification checkpoint (2026-10-26): the source fix is applied. The boundary class passes **36 tests / 866 assertions** (101.968s), including nine upload/review cases, 24 parser inputs and three controlled locked-Session raw-value cases. Focused scanner/offline checks pass **619 tests / 6,706 assertions** (181.555s). Four PHP syntax checks, compiled Blade syntax, read-only scoped Pint, exact 100-route method/path comparison and the 32-table safe CLI checks pass. Normalized source comparison preserves 57 existing method bodies outside the five permitted changes and all original methods in the new boundary test; the Blade has one key-expression replacement plus a final newline. The 338-path test fingerprint and 343 other non-doc baseline paths, including environment, Git index and existing build artifacts, are preserved. The full 1,720-test suite is running; task status remains IN PROGRESS until its final gate and documentation checks pass.

Final verification supersedes the checkpoint: the complete isolated backend suite passed **1,720 tests / 28,050 assertions** (JUnit aggregate 1086.527961s), with zero errors/failures/skips. Focused checks passed **619 / 6,706**, and the boundary class passed **36 / 866**. All 74 prior test classes retain their exact P4-019 test/assertion counts. The retained original-source run reproduced eight failures / 369 assertions / zero errors; it predates the fix and includes the numeric-search HTTP failure. No old tests were removed or weakened. The original nine boundary scenarios/helper bodies remain intact after formatting; 27 parser/locked-value cases were added.

PositiveIntegerId rejects ambiguous/overflow values before conversion. Preview Guest models and QR state are Event-scoped; only exported snapshot identities receive an ID-only existence distinction for WRONG_EVENT. No foreign Guest model/current state or current Guest FK enters new preview conflicts/final states. Review lookup/search uses the same parser and the Blade uses raw external keys. The locked raw Event guard precedes preview rows/files; the existing preliminary upload audit is deliberately preserved. Three guard cases alter only controlled locked-model hydration, not a live database schema.

Four PHP and one compiled-Blade syntax checks, read-only Pint, unchanged 100 method/path pairs, 32-table safe CLI, local documentation checks and the frozen 338-path source comparison passed. All 84 Markdown documents decode as UTF-8 and 1,195 local links resolve. The 20 planned UI task IDs remain unchanged. A normalized method comparison preserves 57 unrelated method bodies, with five intended changed methods, and one Blade expression plus final newline. All 343 other non-doc baseline paths remain unchanged, including .env/app key, Git index and existing builds. Pint also normalizes existing imports/formatting in the touched service. No schema/account/ownership/dependency/configured data/service or deployment change occurred. This completes the bounded task, not Phase 4: current authority/Guest-movement fences, historical stored relations, confirm/resolver handling, scanner recertification and file/commit recovery remain open. [Preview behavior and limits](OFFLINE_SYNC.md).

## Task P4-021 — COMPLETE (2026-10-26)

Objective: prevent confirmation reconciliation from copying foreign current Guest details or applying an offline final count to a Guest that moved to another Event after preview, including a final-state path without a current-import log for that Guest.

Acceptance criteria:

- Validate canonical positive PHP-range raw Session and Import Event identities and exact equality inside the existing locked transaction before processing effects. Reuse PositiveIntegerId for external/snapshot Guest IDs; inspect raw snapshot guest_id before its model cast. Preserve distinct raw external IDs before numeric filtering/deduplication.
- Retain the current transaction/retry and lock phases. Use an ascending ID-only locked existence query over valid exported snapshot Guest IDs, followed by Event-scoped current Guest/QR processing models. Foreign current models/state must not enter reconciliation decisions, new conflict state or admission/final-count effects. This is not a current actor/ownership fence or every-engine lock-schedule guarantee.
- Preserve WRONG_EVENT classification for a moved exported Guest using only the ID-only distinction; new conflicts have null current Guest/state/count bindings while retaining exported snapshot context. Preserve missing/deleted/same-Event behavior and canonical MAX compatibility. Do not rewrite historical stored preview relations or alter the existing freshImport/review eager graph.
- Route every confirmation snapshot-to-current-Guest lookup through the same raw-ID helper, including baseline checks, log application and final-state application. A foreign/unsafe Guest final state must never be applied even when no current-import log marks that Guest blocked. Preserve legitimate same-Event final states, capacity/history semantics and idempotent completed confirmation.
- Reproduce moved-to-unowned and moved-to-owned current-state disclosure through authenticated encrypted previews and confirmation. Reproduce the two-preview/no-current-log final-count mutation without tampering with stored logs. Cover legitimate controls, malformed raw snapshot IDs and locked Event references where feasible, with whole-table/private-file and neighbor conservation.
- Preserve all prior test/assertion contracts. Run focused/full isolated regression, scoped PHP syntax/read-only Pint, unchanged 100-route/32-table safe CLI, source/environment/app-key/Git-index/build preservation and documentation checks.

Scope: the existing OfflineScannerResultsImporter and one new feature-test class, reusing the existing PositiveIntegerId helper, plus docs. No schema, new route, authentication, policy/permission, transaction/retry count, crypto/file/audit order, configured data/service or deployment change. Returned/eager historical Guest/scanner relations, stored old leaks, nested resource lineage, current Event/actor authority, arbitrary writers/Guest movement, resolver actions, scanner recertification and file/commit recovery remain separate gates. Customer event/signup and the planned UI tasks remain closed/not started.


Verification checkpoint (2026-10-26): the unchanged importer reproduced nine failures / 218 assertions / zero errors in the corrected 12-case class. Two earlier harness/control runs are retained separately: the new conservation helper initially omitted the generated active_event_id effect, and a completed-session HTTP retry is already denied by policy; the corrected control preserves HTTP403 and separately proves the internal completed-import no-op. No application policy was changed for those controls. The source fix now passes 12 cases / 488 assertions; focused regression passes 631 tests / 7,194 assertions. Coverage includes two foreign ownership variants, two authenticated two-preview/no-current-log mutation cases, the existing same-Event ledger behavior, ordinary/MAX controls, two controlled raw snapshot aliases and three controlled locked Event references. Both PHP files pass syntax and scoped read-only Pint; 100-route/32-table safe CLI passes. Normalized comparison preserves 16 unrelated importer methods, permits four changed methods plus guestForSnapshot, and conserves all 347 unrelated non-doc baseline paths. The 339-path test fingerprint is frozen. Full 1,732-test regression is running; P4-021 remains IN PROGRESS.


Final verification supersedes that checkpoint: full isolated regression passed **1,732 tests / 28,538 assertions** (JUnit aggregate 277.329330s), focused **631 / 7,194**, boundary **12 / 488**, with zero errors/failures/skips. All 75 prior test classes retain exact P4-020 test/assertion counts. The corrected old-source reproduction has nine failures / 218 assertions / zero errors and three passing compatibility controls. Both earlier harness/control runs are retained separately; no source/policy change was made to satisfy those harness corrections.

The importer now validates canonical raw locked Event identities, locks valid exported Guest IDs in ascending order using ID-only results, loads current Guest/QR processing models only in the validated Event, and routes all snapshot-based processing lookups through one raw-ID helper. New moved-Guest conflicts retain WRONG_EVENT and exported snapshot context with null current Guest/state/count fields. Two valid encrypted previews prove that a foreign final count cannot be applied merely because the current import has no log for that Guest. Controlled hydration cases establish strict raw-key integration, not a malformed live schema or every-engine concurrency outcome. Ordinary/MAX behavior and closed-session HTTP403/internal completed replay are preserved.

Two PHP syntax and read-only scoped Pint checks, unchanged 100 method/path pairs, 32-table safe CLI, frozen 339 source fingerprints and all 347 unrelated non-doc baseline paths pass. The latter include environment/app key, Git index and existing builds. Normalized method comparison preserves 16 unrelated importer methods; four existing methods change and one private guestForSnapshot helper is added. The shared parser, other application/test source, schema, crypto, transaction/retry count, later lock phases and file/audit order are preserved; Pint normalizes existing formatting in the touched importer. No configured data/service, real account/ownership, dependency or deployment changed. P4-021 is COMPLETE; Phase 4 remains IN PROGRESS. Historical/eager return/review relations, old stored state, current actor/Event ownership fences, nested resources, resolver operations, scanner recertification and file/commit recovery remain separate gates.


Documentation gate: all 84 Markdown documents decode as UTF-8 and all 1,201 local links resolve; changed-file whitespace/fenced-block checks and scoped git diff --check pass. The 20 planned UI task IDs remain unchanged. The only source changes are the existing importer and the new independent confirmation feature class; no unrelated source or file is added. The final source-preservation check still matches all 339 frozen test-source paths and 347 unrelated non-doc baseline paths.


## Task P4-022 — COMPLETE (2026-10-26)

Objective: stop authorized legacy result/conflict HTTP reviews from exposing current foreign or numerically aliased Guest/replacement relations and unproven foreign historical server-state fields, while retaining correctly attributed own-Event history.

Acceptance criteria:

- Add one reusable read-only LegacyOfflineGuestReview projection after the existing authenticated legacy head/permission checks. Require canonical matching raw Import/Session/Event IDs; this projection is not an authority grant or concurrency fence. Wire result show/logs/conflicts data and conflict-detail show; remove blind Guest/replacement eager loads from those HTTP paths before projection.
- Batch current Guest references only from canonical raw FKs on matching Event/Import child rows (and Session for final states), reusing PositiveIntegerId before any model cast. Query current Guests only inside the authorized Event. Explicitly assign safe guest/replacement relations, including null, and mask unavailable/unsafe current FK attributes in the in-memory view projection. Overwrite preloaded relations; do not permit a lazy global relation fallback or MAX alias.
- Retain stored conflict server_state/scalars only when its array contains canonical matching Event and valid Guest provenance, with Guest ID equal to a canonical non-null raw bound FK. A physically null bound FK may retain valid own-Event historical state after deletion. Invalid non-null raw FK, foreign/missing/padded Event, or mismatched/unsafe state Guest identity hides server_state/server_used/server_remaining in memory. Preserve valid own-Event historical state when the current Guest moved away; preserve exported snapshot/offline/expected state and resolution metadata.
- Never save/redact database history or private files. Keep projection fields clean in model dirty tracking. Preserve current Guest controls, relevant own historical controls, candidate/navigation/policy behavior, response shapes and existing workflows. Internal freshImport/preview/resolver return graphs, scanner/actor relations and arbitrary historical text are explicit subsequent boundaries, not fixed by this task.
- Reproduce owned/unowned foreign relations and historical state through the three result GETs and conflict detail, including replacement-name HTML and current Guest hydration observations. Cover own current/history-after-move/deletion, null FK, preloaded relation replacement and unsafe raw aliases to a real same-Event MAX Guest. Assert full row/private-file conservation and stable context, with no actual configured data access.
- Preserve prior tests/assertions; run focused/full isolated regression, source/read-only Pint/PHP syntax, unchanged 100-route/32-table safe CLI, frozen source/environment/app-key/Git-index/build and documentation checks.

Scope: one projection service, resultReviewData wiring in the existing shared trait, conflict show wiring, one independent feature class and docs. No schema, writer, admission/RSVP, authentication/policy, transaction/lock/retry/file/audit, dependency, configured data/service or deployment changes. This is a read-projection boundary with exact named coverage, not complete tenant isolation or atomic revocation. Current parent/actor fences, scanner/actor projections, nested history, resolver effects and file/commit recovery remain separate gates.

Final verification: full **1,748 tests / 29,947 assertions** (JUnit aggregate 548.530188s), focused **647 / 8,603** (143.198198s), boundary **16 / 1,409** (10.049487s), zero errors/failures/skips. All 76 prior classes retain exact counts/assertions. The retained original-source reproduction covers the initial 14 cases: ten failures / 420 assertions / zero errors, with four passing compatibility controls. Two child-lineage cases were added afterward; no 16-case old-source run is claimed.

The service projects current Guest/replacement/final-state relations only within canonical matching Import/Session/Event context and explicitly replaces preloaded relations, including null, before previewSummary can load missing relations. Unsafe/unavailable current FK fields are masked in memory with clean dirty tracking. Historical server-state/scalars require matching canonical Event/Guest attribution; valid own-Event history survives Guest movement/deletion. Whole-table/private-file checks establish that each covered GET leaves stored history and files intact. Coverage includes owned/unowned foreign references, invalid stored state, own current/history/null-FK controls, preloaded relations, controlled overflow/newline aliases beside a real MAX Guest, child Event mismatch and final-state Session mismatch.

Four PHP syntax/read-only scoped Pint checks, unchanged 100 route pairs/32-table safe CLI, 341 frozen source paths and 347 unrelated non-doc baseline paths pass, including environment/app key, Git index and existing builds. Normalized comparison preserves 16 unrelated methods and permits only resultReviewData/show changes; one service and one feature class are added. Structural history attribution is not cryptographic proof of arbitrary stored JSON. Internal return graphs, scanner/actor references, broader nested history, current authority/concurrency fences, resolver effects and file/commit recovery remain separate. P4-022 is COMPLETE; Phase 4 and actual database/legacy rollout gates remain open. Planned UI/RSVP work remains NOT STARTED.

Documentation gate: all 84 Markdown documents decode as UTF-8; all 1,207 local links resolve, scoped whitespace/fence checks pass and the 20 planned UI task IDs remain unchanged. Final source comparison still matches 341 frozen paths and 347 unrelated non-doc paths, including environment/app key, Git index and existing builds. P4-022 is COMPLETE in source/tests; no UI/RSVP implementation is claimed.

## Task P4-023 — COMPLETE (2026-10-27)

Objective: prevent current scanner-account names/models from another Event or protected account class appearing in historical legacy offline HTTP review graphs.

Acceptance criteria:

- Add one read-only LegacyOfflineScannerReview service after existing authorized legacy parent checks. Cover setup/create latest and active Session view data, Session show, result show/logs/conflicts, and conflict detail. Remove blind current scanner eager loads before any retrieval; preserve actor relations for a separate policy task.
- Require canonical positive PHP-range raw Session/Import/Event identity and matching child lineage before using scanner FKs. Project Session master/assignments, result devices, full imported logs, separate paginated logs and conflict importedLog instances. Conflict-log identity must agree with its raw bound FK; invalid shared log associations get independent in-memory projection instances.
- Query current Users on primary only inside the validated Event, with exact byte role scanner, known false platform flag and no workspace membership of any role/activity. Validate raw hydrated PK/Event/role/platform fields before mapping. Historical readability permits inactive same-Event ordinary scanners; new-generation active-only rules are unchanged.
- Explicitly set safe or null current relations and overwrite preloaded values without lazy fallback. Preserve canonical historical scanner FK/filter identities and captured scanner_user_name/gate/device data even when the current User becomes unavailable. Mask only noncanonical unsafe scanner FK attributes, synchronize original attributes and never save a projection. This differs deliberately from unavailable current Guest FK masking.
- Reproduce natural scanner rename/reassignment into owned/unowned neighbor Events, protected role/platform/membership changes, own active/inactive history, preloaded relations and mixed overflow/newline aliases beside a legitimate MAX User. Include selected malformed child-lineage cases. Assert response/navigation compatibility, current relation/HTML behavior, no ordinary foreign/protected User hydration, whole-table/private-file conservation and clean dirty tracking.
- Preserve prior tests/assertions; run scoped PHP syntax/read-only Pint, focused/full isolated regression, unchanged100-route/32-table safe CLI, source/environment/app-key/Git-index/build preservation and documentation checks.

Scope: one new service, existing shared-trait latestSessionForEvent/resultReviewData/filteredResultLogs wiring, SessionController create/show, ConflictController show, one independent feature class and docs. No schema, writer, permission/authentication, admission/RSVP, transaction/lock/retry/crypto/file/audit, dependency, configured data/service or deployment change. Actor created/uploaded/resolved/audit policy, arbitrary historical metadata, optional raw filter-request coercion, internal service returns, current ownership/authority fences, resolver effects and file/commit recovery remain separate gates. This is point-in-time read projection, not atomic revocation or complete tenant isolation. Planned UI/RSVP tasks remain NOT STARTED.

Final verification: full **1,764 tests / 33,752 assertions** (JUnit aggregate 354.836494s), focused **663 / 12,408** (114.664824s), boundary **16 / 3,805** (13.523317s), zero errors/failures/skips. All 77 prior classes retain exact counts/assertions. The original 16-case run had 172 assertions / 16 failures / zero errors, including missing explicit-null projection controls; these are not 16 distinct security vulnerabilities. Natural scanner rename/reassignment reproduced foreign current User hydration, and a blank captured-name/filtered case exposed private HTML.

LegacyOfflineScannerReview now projects current scanner relations across all six named HTTP GET graphs, validating canonical raw parent/child identities and the conflict's bound log identity before lookup. Current User models must belong to the validated Event, have byte-exact scanner role, known false platform flag and no workspace membership. Inactive ordinary local scanners remain readable in history; new-generation active-only eligibility is unchanged. Canonical historical scanner FK/filter IDs and captured names/gates/device data remain intact when a current User is unavailable. Only noncanonical unsafe scanner FKs are masked in memory. Independent projections protect valid shared logs from an invalid association; no projection is saved.

Cases cover owned/unowned reassignment, blank-name fallback/filter compatibility, protected account classes, active/inactive own history, preloaded relations, controlled overflow/newline aliases beside a real MAX User and selected child Session mismatches. Whole-table/private-file conservation and clean dirty tracking pass. Five PHP syntax/read-only scoped Pint checks, unchanged 100-route/32-table safe CLI, 343 frozen source paths and 348 unrelated non-doc paths pass, including environment/app key, Git index and builds. Normalized comparison preserves 17 unrelated methods and permits six existing method changes. P4-023 is COMPLETE; actor policy, internal returns, arbitrary historical metadata, current authority/concurrency fences and other declared gates remain open. This does not establish whole-tenant privacy or implement UI/RSVP.

Documentation gate: all 84 Markdown documents decode as UTF-8 and all 1,213 local links resolve; scoped whitespace/fence checks pass and all 20 planned UI task IDs remain unchanged. Final source comparison still matches 343 frozen paths and 348 unrelated non-doc paths, including environment/app key, Git index and existing builds. P4-023 is COMPLETE in source/tests.

## Task P4-024 — COMPLETE (2026-10-27)

Objective: fence the existing legacy HTTP result confirmation against Event ownership, current administrator or parent changes after request-entry authorization, before reconciliation effects.

Acceptance criteria:

- Add one LegacyOfflineResultConfirmation wrapper used only by HTTP confirm. Validate canonical raw original/current Import PK, Session FK, Event FK and selected Event; require the actor's safe current/original identity to agree before database work. Reuse PositiveIntegerId.
- In one outer default-connection transaction with three retries, lock primary Event -> current User -> Session -> Import. Require the exact captured unowned Event, exact active administrator with known true activity, and exact canonical captured Session/Import/Event lineage. Preserve the existing eligibility of platform or workspace-linked administrators; this wrapper grants no customer action.
- Delegate to the existing importVerified/importer using current locked Import and actor. Preserve downstream reconciliation, lock phases, admission/capacity/history, lifecycle/sequence/export validation, completed internal retry and file/audit behavior. The nested transaction shares the same connection; this is not evidence for every engine schedule or an arbitrary external writer.
- Preserve current entry Gates, confirmation validation, response messages and redirects. Explicitly pass HTTP authorization exceptions through the controller's broad runtime catch so late refusals remain generic403/404 rather than validation redirects. Do not alter preview, conflict resolution, secure deletion or direct internal importer callers.
- Reproduce late owned Event, disabled/changed-role/noncanonical activity actors and coherent foreign parent changes after the HTTP Gate using controlled schedules. Denials conserve all post-transition rows/private-file bytes. Verify ordinary/platform/workspace-admin successful admission, current completed internal no-op/closed HTTP403, primary prefix ordering, forced failure rollback and raw caller shapes before queries.
- Retain all prior cases/assertions. Move exactly two controlled P4-021 hydration hooks from transaction level2 to3 so they continue targeting the unchanged importer inside its new outer authority prefix. Do not weaken their malformed-ID checks, response expectations or conservation assertions.
- Run scoped syntax/read-only Pint, focused/full isolated regression, unchanged100-route/32-table safe CLI, source/environment/app-key/Git-index/build and documentation checks. Record controlled fixture evidence separately from actual MySQL concurrency.

Scope: one new wrapper, only ResultImportController::confirm wiring, one independent feature class, three depth-only fixture expressions across two existing test classes and docs. No schema, permission model, importer/RSVP/admission algorithm, dependency, configured database/account/ownership/service or deployment change. Conflict resolver fences, upload's preliminary audits, nested historical return graphs/actor policy, scanner recertification and secure-delete/file/commit recovery remain separate gates. Customer event/signup and planned UI tasks remain closed/not started.

Verification adjustment: the first focused run completed 687 tests / 12,757 assertions with two failures in the existing SQL-feedback fixture's transaction-depth expectation. HTTP confirmation now reaches the unchanged importer at level3; conflict resolution remains level2. Update only that conditional depth expectation, retain every case/rollback/sanitization assertion and rerun. The earlier two P4-021 raw-hydration hooks also target level3. This adds one existing test-method expectation to the declared fixture-only scope; no application fix is needed for these nesting assumptions.

Final verification: Full **1,788 tests / 34,141 assertions** (JUnit aggregate 166.151363s), focused **687 / 12,797** (60.134706s), new boundary **24 / 389** (4.022976s), existing confirmation compatibility **12 / 488** (3.497054s), zero errors/failures/skips. All 78 prior classes retain exact counts/assertions.

LegacyOfflineResultConfirmation now checks canonical raw original/current Import, Session/Event references, selected Event and actor identity before database work. Its outer default-connection transaction locks primary Event → current User → Session → Import. The captured Event must remain unowned; the actor must remain an exact administrator with known true activity; all captured parents must still match. Platform and workspace-linked administrators retain existing legacy eligibility. The unchanged importer runs on the same connection with its existing reconciliation, lifecycle/capacity/history checks and completed internal no-op. The completed HTTP request still returns its existing403; late ownership/parent and actor refusals pass through as generic404/403.

The original HTTP-only run reproduced seven failures / 203 assertions / zero errors in 11 cases; four compatibility/rollback controls passed. Wrapper-only cases were not run against missing source. The new 24 cases cover late ownership/actor/coherent-parent changes, ordinary/platform/member-admin imports, real partial-write rollback, guarded completed retry, prefix query ordering and twelve raw contexts rejected before queries/transactions. Whole-row/private-file snapshots conserve late refusals and rollback.

The initial focused run had two failures caused by an old transaction-depth fixture expectation. Exactly two P4-021 hydration hooks now target importer level3, and the SQL-feedback fixture expects confirmation at3 while conflict resolution remains2. Every prior case, business expectation, rollback/sanitization assertion and assertion count is retained; token-normalized comparison limits these three fixture changes to their depth expressions. The initial failed artifacts remain separately recorded.

Five PHP syntax/read-only scoped Pint checks, unchanged 100 method/path pairs and 32-table safe CLI pass. Source comparison preserves 51 unrelated methods, permits one production method plus three fixture-only methods, and conserves 350 unrelated non-doc paths and 345 frozen source paths, including environment/app key, Git index and existing builds.

This is bounded HTTP confirmation protection, not complete tenant isolation or actual MySQL contention/retry proof. Conflict resolution, upload preliminary audits, nested/internal history and actor policy, scanner recertification and secure-delete/file/commit recovery remain separate gates. No schema, configured data/account/ownership/service, dependency/build or deployment changed. Planned UI/RSVP and all-interface usability work remain unimplemented. P4-024 is COMPLETE in source/tests; Phase 4 remains IN PROGRESS.

Documentation gate: 84 Markdown files decode as UTF-8; 1,219 local links resolve, scoped whitespace/fence checks pass and all 20 planned UI tasks retain their statuses. Source, environment/app-key, Git-index and existing builds still match the 345 frozen /350 unrelated-path checks. P4-024 is COMPLETE. P4-025 remains NOT STARTED in the project; its TEMP drafts are not an implementation or verification claim. The delegated documentation worker reported a usage-limit error; this verified checkpoint was completed by the primary agent.

## Task P4-025 — COMPLETE (2026-10-27)

Objective: prevent legacy HTTP conflict resolution after current Event ownership, administrator eligibility or captured head lineage changes, before resolver effects.

Acceptance criteria:

- Add LegacyOfflineConflictResolution for the existing HTTP resolve action. Validate canonical original/current Conflict PK, Import/Event FKs, selected Event and equal safe actor identities before queries. Read only primary Import identity metadata to capture its canonical Session FK/Event; retain that captured Session across transaction retries.
- An outer default-connection transaction with three retries locks Event → current User → Session → Import → Conflict. Require the exact captured unowned Event, exact active administrator with known true activity and every captured raw parent link. Preserve platform/workspace-linked administrator compatibility and existing lifecycle/action decisions in the unchanged resolver.
- Delegate unchanged resolution using current locked Conflict and actor on the same connection. Keep current controller entry checks, input validation, success response and redirect. Explicitly pass late HTTP403/404 through the broad runtime catch. Do not claim this head fence fixes bound-log, Guest/replacement, sibling effects or internal eager return graphs.
- Reproduce late Event/actor/coherent-parent/Conflict-parent changes after HTTP authorization with complete post-transition row/private-file conservation. Cover actual successful legacy resolutions, closed-conflict HTTP refusal, partial-write rollback, raw contexts rejected before queries, identity-only capture and ordered prefix observations. Controlled SQLite/source evidence is separate from actual MySQL contention.
- Retain every earlier case/assertion; update only the SQL-feedback transaction-depth expectation to level3 for both HTTP actions now that both gain outer wrappers. Preserve all rollback, business and sanitization checks.
- Run scoped PHP/read-only Pint, focused/full isolated regression, unchanged100-route/32-table safe CLI, frozen source/environment/app-key/Git-index/build and documentation checks.

Scope: one wrapper, only ConflictController::resolve wiring, one independent feature class, one existing fixture-depth expression and docs. No schema, admission/resolution algorithm, new permissions/authentication, real database/ownership/account/service, dependency/build or deployment change. Bound-log/Guest/replacement/sibling effects, upload preliminary audits, historical actor/internal graphs, scanner recertification and secure-delete/file/commit recovery remain separate gates. UI/RSVP work remains planned.

Final verification: Full **1,811 tests / 34,505 assertions** (JUnit aggregate 196.443158s), focused **710 / 13,161** (62.27619s), boundary **23 / 364** (7.064692s), existing feedback compatibility **10 / 277** (2.039807s), zero errors/failures/skips. All 79 prior classes retain exact test/assertion counts.

LegacyOfflineConflictResolution validates canonical raw original/current Conflict, Import/Event references, selected Event and actor identity before queries. It captures only primary Import identity metadata to choose a canonical Session, then freezes that parent across retries. Its outer default-connection transaction locks Event → current User → Session → Import → Conflict, requiring the captured unowned Event, exact active administrator and all captured parent links. Platform and workspace-linked administrators retain existing eligibility. The unchanged resolver runs on the same connection with its existing lifecycle/action/admission rules. Late head/ownership and actor changes pass through as generic404/403; resolved-conflict HTTP403 remains unchanged.

The original HTTP-only run reproduced eight failures / 186 assertions / zero errors in12 cases; four successful-resolution/rollback controls passed. Wrapper-only cases were not run against missing source. The23 fixed cases cover late ownership/actor/coherent-parent/Conflict-parent changes, ordinary/platform/member-admin keep/reject actions, actual partial-write audit rollback, ordered identity/lock observations, raw context rejection before queries and failed Import capture. The missing-capture case uses a controlled cloned caller with an absent canonical Import ID; it does not create a dangling live schema or disable constraints. Whole-row/private-file conservation protects denials, successful target-only changes and rollback.

Four PHP syntax/read-only scoped Pint checks, unchanged100 route method/path pairs and32-table safe CLI pass. Token-normalized comparison preserves22 unrelated methods and permits only one controller method plus one fixture expectation. The existing SQL-feedback fixture now expects nested level3 for both HTTP actions at the same assertion; every prior case, rollback/sanitization check and assertion count remains intact. All353 unrelated non-doc baseline paths and347 frozen source fingerprints match, including environment/app key, Git index and existing builds.

This is current HTTP administrator/head protection, not complete resolver isolation or actual MySQL contention/retry proof. Bound-log, Guest/replacement and sibling effects, historical actor/internal eager graphs, upload preliminary audits, scanner recertification and secure-delete/file/commit recovery remain separate gates. The original resolver/admission algorithms and direct internal callers are unchanged. No schema, configured data/account/ownership/service, dependency/build or deployment changed. New invitation/RSVP and all-interface design rollout remain planned. P4-025 is COMPLETE in source/tests; Phase4 remains IN PROGRESS.

Documentation gate: all84 Markdown files decode as UTF-8 and all1,225 local links resolve; scoped whitespace/fence checks pass and all20 planned UI task IDs remain unchanged. Final comparison preserves347 frozen source paths and353 unrelated non-doc paths, including environment/app key, Git index and existing builds. P4-025 is COMPLETE in source/tests; Phase4 remains IN PROGRESS.

## Task P4-026 — COMPLETE (2026-10-27)

Objective: prevent conflict-resolution processing from loading or mutating a bound imported Log outside its locked Import/Session, or aliasing unsafe IDs to another Log.

Acceptance criteria:

- In the existing resolver, parse canonical raw current/original caller Conflict identity before numeric lookup. Remove blind importedLog and unused parent eager loads from the initial locked Conflict read. Keep the existing Conflict → Import → Session → Log lock phases and transaction/retry count; HTTP authority continues to come from P4-025.
- Parse current locked raw Conflict/Import/Session/Event identities before the next numeric lookup and require matching raw Event and parent links. This is structural context for direct internal callers, not an ownership/actor grant. Preserve original lifecycle/action/capacity and admission rules.
- A literal null raw bound-Log FK keeps existing no-Log actions. Reject every non-null unsafe/nonpositive key before any Log lookup. A canonical key must load a current primary locked Log scoped to the same Import and Session, with canonical raw PK/parent identities rechecked after hydration. Missing/foreign/wrong-Import/wrong-Session logs raise a generic conflict_resolution validation error before effects and must not hydrate foreign Log models.
- Explicitly assign the safe current importedLog relation, including null, so manual count adjustment uses that same validated locked Log. Bind safe parent relations as needed to prevent lazy fallback; do not create a second admission or resolution algorithm.
- Reproduce late HTTP bound-Log swaps, including owned/unowned neighbors, same-Event other Import and Session mismatch. Cover controlled raw aliases beside a real MAX Log, preloaded relation replacement, null-Log compatibility and safe manual adjustment. Use complete row/private-file conservation and current model-hydration observations. Clearly distinguish controlled raw hydration from a malformed live schema or actual engine contention.
- Retain all prior test cases/assertions. Run scoped syntax/read-only Pint, focused/full isolated regression, unchanged100-route/32-table CLI, frozen source/environment/app-key/Git-index/build and documentation checks.

Scope: the existing resolver, an independent feature class and docs, reusing PositiveIntegerId. No new schema, permission/identity architecture, actor/ownership wrapper policy, admission algorithm, configured data/account/ownership/service, dependency/build or deployment change. Current Guest/replacement global reads and integer Event comparisons, sibling bulk-update integrity, historical actor/internal return graphs, upload preliminary audits, scanner recertification and secure-delete/file/commit recovery remain separate gates. UI/RSVP work remains planned.

Final verification: Full **1,830 tests / 34,829 assertions** (JUnit aggregate 525.41716s), focused **729 / 13,485** (61.496304s), boundary **19 / 324** (3.444358s), existing import workflow **11 / 189** (4.315815s), zero errors/failures/skips. All80 prior classes retain exact counts/assertions.

The existing OfflineScannerImportConflictResolver now parses canonical raw caller Conflict identity before its transaction, removes blind initial Log/parent eager loads and validates current locked raw Conflict/Import/Session/Event context before subsequent numeric lookups. It retains Conflict → Import → Session → Log lock phases and the existing transaction/retry count. A literal null bound-Log FK preserves existing no-Log actions; every non-null key must be canonical and resolve to a primary locked Log in the same Import and Session, with raw IDs rechecked after hydration. Unsafe/missing/foreign/mismatched Logs fail through generic conflict_resolution validation before effects. Explicit safe/null importedLog and parent relations ensure manual adjustment uses the validated locked Log.

The original19-case run reproduced12 failures /249 assertions /zero errors, with seven compatibility controls passing. New cases cover late owned/unowned/same-Event-other-Import/Session-mismatch Log swaps, literal-null actions, safe manual adjustment, malicious preloaded relations, controlled unsafe Log/caller aliases beside real MAX records, canonical MAX controls and direct current-parent mismatches. Full-table/private-file conservation exposes old unintended writes and verifies fixed refusals; normal foreign Log hydration is observed independently. Controlled hydration is not a malformed live schema or MySQL contention rehearsal.

Two PHP syntax/read-only scoped Pint checks and unchanged100-route/32-table safe CLI pass. Token-normalized comparison preserves14 existing methods, including every action/admission method; only resolve changes and one private lockedLogForConflict helper is added. Pint normalizes existing whitespace in the touched class. All356 unrelated non-doc baseline paths and348 frozen source fingerprints match, including environment/app key, Git index and existing builds.

HTTP ownership/administrator permission remains the P4-025 wrapper; structural checks inside direct resolution do not grant new authority. Guest/replacement global reads and integer Event comparisons, sibling bulk-update integrity, historical actor/internal return graphs, upload preliminary audits, scanner recertification and secure-delete/file/commit recovery remain separate gates. No admission algorithm, schema, route, permission architecture, configured data/account/ownership/service, dependency/build or deployment changed. New invitation/RSVP and all-interface design rollout remain planned. P4-026 is COMPLETE in source/tests; Phase4 remains IN PROGRESS.

Documentation gate:84 Markdown files decode as UTF-8; all1,231 local links resolve, scoped whitespace/fence checks pass and all20 planned UI task IDs remain unchanged. Final comparison preserves348 frozen source paths and356 unrelated non-doc paths, including environment/app key, Git index and existing builds. P4-026 is COMPLETE in source/tests.

## Task P4-027 — COMPLETE (2026-10-27)

Objective: keep resolver bound/replacement Guest processing and QR hydration inside the validated Event, without numeric aliases or foreign Guest models.

Acceptance criteria:

- Reuse PositiveIntegerId for raw bound Guest FKs and replacement input before numeric lookup; validate the raw current Event. Reject ambiguous/overflow/padded/newline forms rather than casting them to a real Guest.
- Preserve the Guest lock phase and existing missing-versus-wrong-Event behavior with an ID-only primary locking existence read. Load current Guest models only in the validated Event, recheck canonical raw PK/Event before QR hydration, then load that Guest's existing QR relation on primary. No foreign Guest/QR models enter count/admission decisions.
- Share the safe locked Guest lookup between bound-Guest actions and replacement mapping. Preserve existing validation fields/messages, missing-record exception behavior, literal-null Guest rules, count/capacity/history, Single/Double/Family semantics and existing admission applier. No new pass/token or admission algorithm.
- Reproduce moved-to-owned/unowned bound/replacement Guests and unsafe raw/input aliases beside real MAX Guests. Cover own manual count, permitted MAX Double/Family admission and over-capacity/null-Guest controls. Assert absence of foreign Guest/QR hydration, complete row/private-file conservation on denial and exact allowed target/history effects on success. Controlled hydration is not a malformed live schema or MySQL contention proof.
- Preserve prior cases/assertions. Run scoped PHP/read-only Pint, focused/full isolated regression, unchanged100-route/32-table safe CLI, source/environment/app-key/Git-index/build and documentation checks.

Scope: the existing resolver's two Guest lookup methods plus one shared private helper, an independent feature class and docs. The P4-025 authority wrapper and P4-026 Log checks remain intact. No schema, permission architecture, admission algorithm, real data/account/ownership/service, dependency/build or deployment change. Sibling bulk-update integrity, atomic QR mutation/recertification, internal historical return graphs/actor policy, upload preliminary audits and secure-delete/file/commit recovery remain separate gates. New UI/RSVP work remains planned.

Verification checkpoint: the new15-case class passes231 assertions. Corrected old-source reproduction:15 tests /220 assertions /nine failures /zero errors, with six compatibility controls passing. Two initial fixture runs are retained separately: the project has no GuestFactory, and the Family mapping control originally assumed closure despite other open conflicts. The fixture now creates MAX Guests through the existing model, and the control preserves pending Import/Session state and checks unrelated conflicts rather than changing production behavior. Existing cases/assertions are untouched; focused/full verification remains pending.

Final verification: Full **1,845 tests / 35,060 assertions** (JUnit aggregate 327.250514s), focused **744 / 13,716** (136.497106s), boundary **15 / 231** (4.401653s), existing import workflow **11 / 189** (11.135806s), zero errors/failures/skips. All81 prior classes retain exact counts/assertions.

Resolver bound-Guest and replacement lookups now use canonical raw/input IDs and the validated raw Event. A shared lockedGuestInEvent helper retains the Guest lock phase through an ID-only primary existence read, preserving missing-record exception and wrong-Event validation behavior without loading foreign Guest details. Current Guest models are queried only in that Event and their raw PK/Event are rechecked before the existing QR relation is loaded on primary. Both action paths use this helper; there is no new pass, token or admission algorithm. Existing Single/Double/Family capacity, history, manual-count and literal-null rules remain intact.

The corrected old-source15-case run reproduced nine failures /220 assertions /zero errors, with six compatibility controls passing. Two earlier fixture runs are retained separately: GuestFactory does not exist, and the Family control initially expected closure while other conflicts remained open. The corrected fixture uses the existing Guest model and preserves pending Import/Session state for unrelated open conflicts. These corrections changed no production policy or prior tests. New cases cover late owned/unowned bound/replacement movement, no foreign Guest/QR hydration, unsafe aliases beside MAX, valid MAX Double/Family admission, manual zero, full Single refusal, null Guest behavior and raw Guest Event validation before QR. Complete row/private-file snapshots verify denial and exact target/history changes.

Two PHP syntax/read-only scoped Pint checks and unchanged100-route/32-table safe CLI pass. Token-normalized comparison preserves14 existing methods, including Log validation and admission application; two Guest lookup methods change and one private lockedGuestInEvent helper is added. All357 unrelated non-doc baseline paths and349 frozen source fingerprints match, including environment/app key, Git index and existing builds.

P4-025 current HTTP authority and P4-026 Log processing checks remain intact. Sibling bulk-update integrity, atomic QR mutation/recertification, historical actor/internal return graphs, upload preliminary audits and secure-delete/file/commit recovery remain separate gates. Controlled SQLite/hydration evidence does not establish malformed live-schema or MySQL contention behavior. No schema, route, permission architecture, admission algorithm, configured data/account/ownership/service, dependency/build or deployment changed. New invitation/RSVP and all-interface design rollout remain planned. P4-027 is COMPLETE in source/tests; Phase4 remains IN PROGRESS.

Documentation gate:84 Markdown files decode as UTF-8 and all1,237 local links resolve; scoped whitespace/fence checks pass and all20 planned UI task IDs remain unchanged. Final source comparison preserves349 frozen source paths and357 unrelated non-doc paths, including environment/app key, Git index and builds. P4-027 is COMPLETE in source/tests.

## Task P4-028 — COMPLETE (2026-10-28; opened 2026-10-27)

Objective: prevent sibling conflict bulk updates from mutating inconsistent Event/Import/Log children or including newly inserted, unvalidated rows.

Acceptance criteria:

- Before Guest/action effects for actions that update siblings, lock only identity/status columns of currently OPEN siblings for the validated Import and non-null Log, excluding the already locked head, ordered by canonical PK. Do not prefilter Event: a preexisting foreign/malformed child must refuse the entire operation through generic conflict_resolution validation, without silent repair or partial action effects.
- Validate raw sibling PK/Event/Import/Log and exact OPEN status; freeze the validated IDs inside each existing transaction attempt. Later bulk updates target only those IDs and repeat Event/Import/Log/open predicates. New or changed unvalidated children must not be updated; authoritative open-conflict readiness remains in effect.
- Preserve literal-null Log no-sibling behavior and escalation's independent no-sibling path, so a valid head can still be flagged when another sibling is inconsistent. Resolved/dismissed history and unrelated Import/Log records are outside this write set and remain unchanged.
- Cover owned/unowned bad open siblings, valid/MAX siblings, unrelated/resolved history, null Log, escalation, controlled post-freeze insertions and changed-row predicates. Preexisting bad children must refuse before Guest hydration or DML. Controlled same-transaction hooks are not actual MySQL races or phantom/external-writer fencing evidence.
- Retain all prior cases/assertions and existing admission/action algorithms. Run scoped syntax/read-only Pint, focused/full isolated regression, unchanged100-route/32-table safe CLI, frozen source/environment/app-key/Git-index/build and documentation checks.

Scope: existing resolver's resolve/sibling update methods and a new private identity helper, an independent feature class and docs. HTTP authority still comes from P4-025, Log checks from P4-026 and Guest checks from P4-027. The new sibling phase follows current Log validation and precedes Guest/action effects. Ascending sibling order does not prove direct internal calls deadlock-free: existing target Conflict/Import acquisition can already form cycles; normal same-Event HTTP calls serialize through the Event wrapper. No schema, permission architecture, admission algorithm, real data/account/ownership/service, dependency/build or deployment change. Atomic QR mutation/recertification, internal historical graphs/actor policy, upload preliminary audits and secure-delete/file/commit recovery remain separate gates. New UI/RSVP work remains planned.

Final verification: Full **1,855 tests / 35,450 assertions** (JUnit aggregate 210.158937s), focused **754 / 14,106** (155.969319s), boundary **10 / 390** (4.334873s), existing import workflow **11 / 189** (11.409641s), zero errors/failures/skips. All82 prior classes retain exact counts/assertions.

Resolver actions that update siblings now select only identity/status columns of the current OPEN sibling set on primary, lock in ascending PK order, validate canonical raw Event/Import/Log links and exact OPEN status, then freeze those IDs before Guest/action effects. The query deliberately has no Event prefilter: a preexisting inconsistent child rejects the whole operation rather than being silently ignored or repaired. The later bulk update targets only frozen IDs and repeats Event/Import/Log/open predicates. Literal-null Log and escalation paths skip siblings, retaining their existing behavior; resolved history and unrelated Import/Log records remain outside this write set. Newly inserted or changed rows stay OPEN, and authoritative readiness checks keep the Import/Session pending.

The original10-case run reproduced five failures /233 assertions /zero errors; five compatibility controls passed. New coverage includes owned/unowned bad OPEN siblings refusing before Guest/QR hydration or DML, valid/MAX siblings, resolved/unrelated history, null Log, escalation, controlled own/foreign post-freeze insertions and a controlled validated-row Event change. Full-table/private-file snapshots verify complete refusal for preexisting damage and exact authorized target effects for later insert/change hooks. Those same-connection hooks do not establish real MySQL races, phantom prevention or arbitrary external-writer fencing.

Two PHP syntax/read-only scoped Pint checks and unchanged100-route/32-table safe CLI pass. Token-normalized comparison preserves15 existing methods; only resolve and resolveSiblingConflicts change, with one private lockedSiblingIds helper added. All358 unrelated non-doc baseline paths and350 frozen source fingerprints match, including environment/app key, Git index and existing builds. The prior authority, Log and Guest/QR processing checks remain intact.

Ascending sibling order does not prove direct internal calls deadlock-free: existing target Conflict/Import acquisition can already form cycles. Normal same-Event HTTP writes serialize through the Event wrapper; actual engine schedules remain separate. Upload preliminary audits and preview/file commit recovery, secure-delete partial effects, atomic QR mutation/recertification and historical actor/internal return graphs remain open. No schema, route, permission architecture, admission algorithm, configured data/account/ownership/service, dependency/build or deployment changed. New invitation/RSVP and all-interface design rollout remain planned. P4-028 is COMPLETE in source/tests; Phase4 remains IN PROGRESS.

Documentation gate:84 Markdown files decode as UTF-8; all1,243 local links resolve, scoped whitespace/fence checks pass and all20 planned UI task IDs remain unchanged. Final comparison preserves350 frozen source paths and358 unrelated non-doc paths, including environment/app key, Git index and builds. P4-028 is COMPLETE in source/tests.

## Task P4-029 — COMPLETE (2026-10-28; documentation only)

Objective: document the actual upload/audit/file transaction boundaries and a safe sequence for the remaining authority/file work, without introducing an unsafe outer transaction or changing file policy.

Acceptance criteria:

- Inspect upload callers, preliminary Uploaded/VerificationFailed/Reimport audit writes, storePreview transaction/retry/cleanup scope and secure-delete byte/metadata/audit ordering. Distinguish source observations, existing isolated test evidence and unverified failure hypotheses.
- Record why one outer verifyUpload transaction would roll back expected failed-verification audits and place outer-commit failure beyond existing preview cleanup; why byte deletion cannot be rolled back by SQL; and why default-read fresh()/relations cannot replace a current locked namespace.
- Schedule separate bounded work for current preliminary audit writes, prefix checks inside the existing preview transaction and explicit artifact/deletion recovery. Preserve existing validation/action/retention behavior and do not imply recovery is already implemented.
- Update canonical/specialist references and risk register concisely. Preserve every application/test/schema/config/dependency/build/environment/index path and all350 retained tested-source fingerprints. Retain P4-028's1,855/35,450 source gate by exact preservation, not a new backend-suite claim. Check unchanged100 routes/32-table safe CLI plus documentation links/UTF-8/whitespace.

Scope: documentation and TEMP verification only. No new runtime caller, transaction, writer, migration, file operation, permission, actual database/ownership/account/service, dependency/build or deployment change. No real file/commit or MySQL failure is injected. New UI/RSVP work stays planned; actual owner/restore/hosting/device gates remain open.

Final audit verification: all360 non-document baseline paths and350 retained tested-source fingerprints match. Fresh read-only CLI checks preserve100 method/path pairs,32 catalog tables and rollout_ready=false. All85 Markdown files decode as UTF-8; all1,267 local links resolve;12 scoped whitespace/fence checks and20 unchanged planned UI task IDs pass. P4-028's1,855 tests /35,450 assertions are retained by source preservation, not rerun for this documentation task. New specialist audit and R31–R33 distinguish observed source, existing isolated deletion-feedback evidence and unverified retry/commit hypotheses. P4-029 is COMPLETE; no runtime/file/data/schema/policy change or recovery claim. [Audit and next scopes](OFFLINE_UPLOAD_FILE_EFFECTS.md).

## Task P4-030 — COMPLETE (2026-10-28)

Objective: fence external preliminary upload/failure/reimport audit writes with current Event/administrator/Session authority while preserving their persistence independently of preview success.

Acceptance criteria:

- Add a scoped LegacyOfflineUploadAudits service for the three named preliminary actions. Validate canonical original/current Session/Event and actor identities plus explicit selected Event before queries. Each record uses its own default-connection transaction with primary Event → current active exact administrator → Session locks and captured unowned/matching lineage. Preserve platform/workspace-admin compatibility and existing generic AuditService record format.
- verifyUpload receives the explicit selected Event from its sole runtime controller caller. The initial audit returns current locked Session/actor models, replacing default-read Session::fresh substitution for this scope. Existing validation relation loading remains a point-in-time observation, not a grant for preview writes.
- Failed validation records its authorized failure audit in a separate transaction before rethrow, so preview failure does not roll back prior committed audit traces. Route only the external duplicate-attempt audit through this guard; the duplicate check already inside storePreview keeps its existing transaction context until the later preview task. Add explicit HTTP403/404 passthrough before the preview controller's broad Throwable catch.
- Do not wrap verifyUpload/storePreview in a new outer transaction or change storePreview/file/crypto/cleanup behavior. Keep its remaining authority/recovery gaps explicit. No new file/commit or deletion guarantee.
- Reproduce late context changes after the HTTP Gate, failure-stage and duplicate-stage changes; preserve all post-transition rows/files except previously authorized audits. Verify allowed invalid-file audit persistence, ordinary/platform/member valid preview compatibility, raw malformed context before queries and exact prefix observations.
- Preserve all prior cases/assertions. Adjust only the old controlled preview hydration fixture to target the storePreview Session read after its preliminary Uploaded audit exists, avoiding the new earlier audit Session lock; retain its malformed-ID/feedback/conservation assertions.
- Run scoped PHP/read-only Pint, focused/full isolated regression, unchanged100-route/32-table CLI, source/environment/app-key/Git-index/build and documentation checks.

Scope: one audit guard service, verifyUpload/duplicate audit wiring and constructor dependency in the existing import service, preview controller call/catch, one independent test class, one old fixture hook condition and docs. No schema, permission architecture, admission/QR/resolution algorithm, file operation/order, actual data/account/ownership/service, dependency/build or deployment change. Preview storage authority belongs inside its existing transaction in the following task; artifact retry/ambiguous-commit and secure-delete recovery remain separate. New UI/RSVP work remains planned.


Verification: full **1,871 tests / 35,732 assertions** (677.618121s), focused **770 / 14388** (1752.13335s), boundary **16 / 282** (6.67348s), existing import workflow **11 / 189** (8.852119s); no errors, failures or skips. All 83 prior classes retain exact case/assertion counts. The corrected original-source HTTP-only run reproduces seven failures /189 assertions across 11 cases, with zero errors and four compatibility controls passing. Five helper-only cases were excluded while that class was absent. An initial new-test expectation of two exported logs was corrected to the fixture's actual three; that artifact is retained separately and no prior product assertion was weakened.

Five scoped syntax/read-only Pint checks and unchanged 100-route/32-table CLI pass. Token comparison preserves52 existing methods outside the five scoped changes (constructor, upload/duplicate wiring, preview action and one old test hook). The old preview hook now waits for the preliminary Uploaded audit before targeting its original locked preview row; all prior assertions remain. All 357 unrelated non-doc baseline paths and 352 tested-source fingerprints match, including environment/app key, Git index and builds.

Only external preliminary audit writes are fenced here. Validation relations remain point-in-time observations; preview storage and its internal duplicate audit retain their existing transaction and require a separate current-authority prefix inside it. Artifact retries, uncertain commits and secure-delete recovery remain separate. No schema, route, admission algorithm, configured data/account/ownership/service, dependency/build or deployment change. Invitation/RSVP and the common simple UI remain planned. P4-030 is COMPLETE in source/tests; Phase4 remains IN PROGRESS.

Documentation verification:85 Markdown files decode as UTF-8;1,271 local links resolve,12 scoped whitespace/fence checks pass and all20 planned UI task IDs remain unchanged.


## Task P4-031 — COMPLETE (2026-10-28)

P4-030's verification and documentation gate is complete. Keep Phase4 and all actual ownership/restore/customer-exposure gates open.

Objective: enclose preview records/files and the internal duplicate-attempt audit with current Event/administrator/Session authority inside storePreview's existing transaction and cleanup boundary.

Acceptance:

- Pass explicit selected Event from verifyUpload to storePreview. Before its transaction, capture canonical original/current Session PK/Event and actor identity, selected Event and the validated envelope's Event equality. Freeze captured IDs across transaction retries; do not substitute a newly moved parent.
- Inside the existing three-attempt default-connection transaction, lock primary Event → current active exact administrator → Session. Require captured unowned Event and exact raw parent IDs. Preserve platform/workspace-admin legacy eligibility. No package/session-user relation loading before these parent checks.
- Preserve the established invalid raw locked Session Event ValidationException and its message. Canonical parent movement/ownership mismatch returns generic404; current administrator eligibility loss returns403 through the existing preview controller passthrough.
- Use the current locked actor and Session for every preview reference/audit/path. Then run existing lifecycle/package validation, internal duplicate check, crypto/payload/Guest processing, file placement and cleanup unchanged. The preview transaction remains the file operation owner; do not add an outer transaction or move the cleanup catch.
- Verify late Event/actor/Session changes after earlier authorized upload/validation with full post-transition row/file conservation and no file put; positive ordinary/platform/member controls with actual primary prefix observations; deterministic post-put/child-insert rollback cleanup; the in-transaction duplicate attempt and lifecycle/package validation controls.
- Preserve P4-030 external audit semantics and every prior case/assertion. Run isolated boundary/workflow/focused/full suites, scoped syntax/read-only Pint, unchanged100-route/32-table CLI and source/docs preservation checks before completing the task.

Scope: existing verifyUpload call and storePreview method plus Event import, independent test class and docs. No schema, route, authority grant, admission algorithm, dependency/build/environment, actual data/account/ownership/service or deployment change.

Limits: package/scanner recertification and ordinary relationship snapshot behavior, returned historical graphs, atomic QR mutation, per-attempt retry/uncertain-commit recovery, cleanup failure and secure-delete byte/DB outcomes remain separate. Controlled SQLite hooks and fake files do not establish actual MySQL contention or uncertain commit recovery. Known inside-catch rollback/cleanup probes must not be described as crash-safe storage.

Verification: full **1,884 tests / 36,095 assertions** (234.424267s), focused **783/14,751** (66.296736s), boundary **13/363** (1.627078s), existing import workflow **11/189** (4.468227s); zero errors, failures or skips. All 84 prior classes retain exact case/assertion counts. The original-source 13-case run reproduced eight failures /272 assertions /zero errors: five late-authority effect refusals and three newly required prefix observations. Five compatibility controls passed; these are not eight distinct vulnerability claims.

The new LegacyOfflinePreviewStorageTest covers five late Event/actor/Session changes, three ordinary/platform/member-administrator controls with primary-prefix observations, two known caught post-put/child-insert failures, an in-transaction duplicate, cancellation and changed package binding. Denials conserve the complete post-transition rows/files and previously authorized Uploaded audit. The two fault probes verify rollback and removal of invented fake-disk attempt bytes inside the existing catch; they do not establish crash, ambiguous-commit, cleanup-failure or real-storage recovery.

Two scoped PHP syntax/read-only Pint checks and unchanged 100-route/32-table CLI pass. Token comparison preserves 18 existing methods; only verifyUpload's final selected-Event argument and storePreview change, with the Event import added. The preview tail remains identical except for using the current locked actor. All 361 unrelated non-doc baseline paths and 353 tested-source fingerprints match, including environment/app key, Git index and builds. No prior test case or assertion was changed.

The existing preview transaction still owns file placement and cleanup; no outer transaction was added. Existing crypto/payload validation, lifecycle/package rechecks, Guest processing, internal duplicate behavior and file order remain intact. Package/scanner child recertification, returned/internal historical graphs, per-attempt artifact/uncertain-commit recovery, cleanup failure and secure-delete outcomes remain separate. Controlled SQLite hooks and fake files do not prove actual MySQL scheduling or crash-safe storage.

No schema, route, grant, admission algorithm, configured data/account/ownership/service, dependency/build or deployment change. Invitation/RSVP and the common simple UI remain planned. P4-031 is COMPLETE in source/tests; Phase4 and actual rollout gates remain IN PROGRESS.

Documentation verification: 85 Markdown files decode as UTF-8; 1,277 local links resolve, 12 scoped whitespace/fence checks pass and all 20 planned UI task IDs remain unchanged.


## Task P4-032 — COMPLETE (2026-10-28; TEMP observations/documentation only)

Objective: reproduce or reject the remaining per-attempt preview-file retry hypothesis using deterministic invented-data observations before changing recovery policy.

Acceptance:

- Run only a TEMP PHPUnit probe against an explicitly asserted SQLite :memory: connection, at actual autocommit level0 with fake private storage under the task TEMP directory. Refuse any other driver/database before migrations. No workspace application/test/schema or configured service/data change.
- After a real fake-disk put, inject a child INSERT refusal whose synthetic `database is locked` message exercises the installed Laravel concurrency detector. Observe actual PDO rollback and transaction callback retries; identify this as message-based deterministic fault injection, not a real lock conflict or uncertain commit.
- Case1: remove the trigger before retry2, observe successful preview and every attempt path, committed references and unreferenced artifacts. Case2: retain it for all three attempts, observe final refusal, cleanup calls and remaining files. Case3: use a nonconcurrency child refusal to preserve the existing one-attempt cleanup control.
- Assert exact authorized Uploaded traces, known preview/child rows and complete row/file conservation outside declared fixture effects. Retain neighboring records/files, unique attempt UUID paths and transaction-level/attempt observations. Record actual results honestly; these are observations of current source, not a repaired behavior claim.
- Retain P4-031's1,884 tests/36,095 assertions by all353 frozen source fingerprints and all448 non-doc/document baseline paths outside scoped docs. Run probe syntax/read-only Pint, unchanged100-route/32-table safe CLI and documentation checks. No need to rerun the unchanged full backend suite for this probe/documentation task.

Scope: TEMP-only test/runner/evidence and documentation. No runtime writer, cleanup/retention/quarantine policy, outer transaction, migration, route, account/ownership/service, dependency/build/environment or deployment change.

Limits: deterministic callback rollback/retry is not MySQL contention, a network fault, final-commit failure, lost acknowledgment, cleanup failure or crash recovery. Unknown commit outcomes require separate outcome-aware design; do not delete possibly committed data based only on this probe.

Verification: the isolated external TEMP probe passed **3 cases / 269 assertions** (1.128945s), with zero errors/failures/skips. Its connection is explicitly SQLite :memory: before migrations and the exercised upload starts at actual autocommit level0. Invented neighboring rows/files and authorized Uploaded traces are preserved; distinct attempt UUID paths and real callback rollback/retries expose the recorded artifact outcomes. [Outcome table and limits](OFFLINE_UPLOAD_FILE_EFFECTS.md).

P4-032 confirms a deterministic callback-rollback/retry artifact gap in the unchanged preview source. At actual transaction level0 on SQLite :memory: with fake private storage, a child INSERT trigger fails after a successful file put. A synthetic `database is locked` message is recognized by the installed Laravel concurrency detector and retries the callback after SQL rollback. Success on retry2 leaves one earlier unreferenced attempt file beside the committed file; three failed attempts leave two earlier unreferenced files after only the final path is cleaned. An ordinary nonconcurrency refusal takes one attempt and leaves no attempt artifact.

All363 unrelated non-document baseline paths and all353 retained tested-source fingerprints match, including application/tests/schema, environment/app key, Git index and builds. The **P4-031 1,884 tests / 36,095 assertions** regular-suite gate is retained by exact preservation, not rerun or enlarged for this probe. Probe syntax/read-only Pint and unchanged100-route/32-table safe CLI pass.

These are observations of current behavior using invented rows/bytes and message-based fault injection, not a runtime repair, real lock conflict or MySQL schedule. Known SQL rollback/retry is distinct from final-commit failure, lost acknowledgment, ambiguous commit, cleanup failure and crash recovery; those outcomes remain unverified. No cleanup, retention, quarantine or deletion policy changes. Do not infer that possibly committed files may be deleted.

P4-032 is COMPLETE as a probe/documentation task. No workspace application/test, configured data/account/ownership/service, dependency/build or deployment change. R32 is now supported by deterministic retry-artifact evidence only; uncertain commit and R33 remain open. Phase4, actual rollout and planned invitation/RSVP/common simple UI gates remain unchanged.

Documentation verification: 85 Markdown files decode as UTF-8; 1,287 local links resolve, 12 scoped whitespace/fence checks pass and all 20 planned UI task IDs remain unchanged.


## Task P4-033 — COMPLETE (2026-10-28; design only)

Objective: define and review a bounded cleanup protocol for the known callback-rollback/retry artifacts reproduced by P4-032 before changing runtime file deletion.

Acceptance:

- Inspect the installed transaction, callback-failure, commit-failure, rollback-manager and nested-caller paths. Distinguish an afterRollBack callback from proof of a successful physical rollback: manager callbacks can also run during lost-connection handling and nested concurrency propagation.
- Evaluate immutable per-attempt path/disk capture, marker registration only on callback failure, and cleanup of marked prior artifacts only after the framework actually re-enters a retry and current Event/administrator/Session checks succeed. Commit-failure paths must never become deletion candidates merely because they are unreferenced in a snapshot.
- Review false/throwing deletion, missing files, ownership/actor loss before retry, nested callers, callback lifetime and possible commit-retry manager records. Preserve the existing three-attempt transaction and avoid new global dispatcher/listener state or an outer transaction.
- Obtain independent read-only reviews. Document a concrete safe continuation contract or unresolved counterexamples. This design task changes no runtime cleanup and does not declare recovery complete.
- Preserve every application/test/schema/config/environment/index/build path and all353 retained tested-source fingerprints. Retain P4-031's1,884/36,095 backend gate by exact preservation; check scoped documentation and unchanged100-route/32-table CLI.

Scope: read-only code review, TEMP design evidence and documentation. No real file/data/account/ownership/service, migration, dependency/build, retention/quarantine policy or deployment change. P4-032's existing fake-file observations remain evidence of the current defect.

Limits: a future retry-only repair may address known callback rollbacks without resolving final caught cleanup authority, unknown commit, cleanup failure, outer-caller rollback, process crash or durable reconciliation. Name those limits before implementation; never claim that an afterRollBack notification alone authorizes deleting possibly committed data.

Design verification: two independent read-only reviews examined the installed callback/commit/rollback manager paths and the proposed protocol. The design now requires callback-only immutable candidates, strict root/same-PDO/open-state eligibility, a non-effecting rollback marker, actual retry re-entry, fresh authority, current-path reset and a current primary locking ID-only veto for any matching artifact path, including ambiguous disk metadata. Marker/PDO state alone is insufficient. Controlled raw commit and raw commit-then-begin counterexamples are required future tests, not newly executed observations.

The proposed protocol and acceptance matrix are recorded in [retry artifact recovery](OFFLINE_RETRY_ARTIFACT_RECOVERY.md). All363 unrelated non-doc paths and353 tested-source fingerprints match. P4-031's1,884 tests/36,095 assertions remain the latest regular-suite gate by exact preservation; no new backend suite was run. Read-only100-route/32-table CLI is unchanged. No application/test/schema/config/environment/index/build, actual data/account/ownership/service or deployment changes.

P4-033 is COMPLETE as a design audit only. A separately bounded implementation must prove the retry path and committed-reference veto before any runtime recovery claim. R32 remains open; final catch/unknown commit, cleanup-failure reconciliation, caller-owned transactions, arbitrary external writers and R33 remain separate. The planned invitation/RSVP and common simple UI are unchanged.

Documentation verification: 86 Markdown files decode as UTF-8; 1,302 local links resolve, 13 scoped whitespace/fence checks pass and all 20 planned UI task IDs remain unchanged.


## Task P4-034 — COMPLETE (2026-10-29)

Objective: repair the known root callback rollback/retry artifact gap reproduced in P4-032, using the reviewed P4-033 protocol without claiming general commit or crash recovery.

Acceptance:

- Scope new cleanup to invocations entering at actual default-connection transaction level0/PDO autocommit. Preserve the existing three-attempt transaction/catch; ambient caller transactions retain their prior compatibility and no new cleanup marker path.
- Reset current path/disk on every callback entry before any failing guard. Capture immutable attempt identity, actual PDO and actual path/disk used for put; Import/audit metadata and file operations must agree. Preserve the current Event/active administrator/Session prefix and all validation/Guest/admission behavior.
- Register a marker only from a failing callback with an assigned path, strict root eligibility, current Laravel level1, the same PDO instance and PDO still inTransaction. Marker callbacks only mark immutable local data; they perform no query/file/log effect and cannot turn commit failures into callback candidates. If eligibility observation fails, do not mark; preserve the original exception.
- Consume prior markers only on a later callback in this same transaction loop, after current namespace revalidation and root/PDO checks. Before any cleanup, perform current primary locking ID-only reference veto for ANY matching encrypted_file_path, without Event/disk prefilters. Positive references, including null/different disk metadata, stop generically before file access/new put; do not hydrate foreign private Import models. Row absence alone is never cleanup authority.
- Clean only marked prior artifacts on their captured private disks. Missing files may be treated as cleaned. False or throwing deletion must stop before another put and leave the artifact outcome unresolved; no sweeping of unmarked paths. Preserve successful-callback/commit-error artifacts, refused-namespace artifacts and the two controlled raw-PDO commit counterexamples. No new global listener/dispatcher state or retention/quarantine policy.
- Keep the existing final catch separately bounded to its current attempt and captured disk. Do not broaden it into unknown-commit or caller-rollback reconciliation. State its remaining outcome limits explicitly.
- Add an independent autocommit0/in-memory SQLite regression class: root retry success/exhaustion, ordinary failure, missing file, deletion false/throw, authority loss before retry, captured disks, current reference veto including foreign/null-disk compatibility, raw PDO commit with/without rebegin and successful callback/commit-error exclusion. The null-disk compatibility shape may alter only its asserted SQLite :memory: fixture, never an application migration.
- Reproduce relevant original-source failures before implementation; preserve every prior case/assertion. Run boundary/workflow/focused/full isolated regression, scoped syntax/read-only Pint, unchanged100-route/32-table CLI, source/environment/index/build and documentation checks. Actual MySQL lock breadth/indexing/schedules remain separate.

Scope: existing storePreview method, a private cleanup helper and necessary imports; one new independent test class and documentation. No schema/migration, route, grant, admission algorithm, actual data/account/ownership/service, dependency/build/environment or deployment change.

Limits: closed single-default-connection DML callback assumptions remain explicit. Positive reference veto is defensive, not proof against arbitrary external hard-delete/rebinding or filesystem aliases. Final commit/lost acknowledgment, process crash, cleanup-failure reconciliation, caller-owned transactions, package/scanner child recertification, historical return graphs and secure-delete outcomes remain separate. Preserve P4-032/P4-033 historical evidence rather than relabeling it as implementation.

### IMPLEMENTED — P4-034 verification and limits (2026-10-29)

P4-034 implements bounded cleanup for artifacts from a failed root transaction callback only after this invocation actually enters a later retry. Each callback resets its current cleanup path/disk before guards; immutable marked attempts retain their path, disk and actual PDO. Registration occurs only in the callback catch with root entry, level1, same-PDO and an open physical transaction. The rollback callback only marks local state. A later callback rechecks the current Event → active administrator → Session prefix and root/PDO identity before cleanup.

Before touching a prior file, a current primary locking ID-only Import lookup vetoes ANY matching encrypted_file_path, without Event or disk filtering or foreign private Import hydration. The captured disk selects the file. A missing file is tolerated; false/throwing deletion stops before another put. Storage exceptions are wrapped with a fixed nonconcurrency message so Laravel cannot silently retry them as database contention. No outer transaction, global listener, artifact scan or retention/quarantine policy is added.

The independent OfflinePreviewRetryArtifactCleanupTest passes **17 cases / 783 assertions** at actual SQLite :memory: autocommit0 with fake private files. Existing workflow **11/189**, focused **800/15,534** and full **1,901 tests / 36,878 assertions** pass with zero errors, failures or skips. All85 prior classes preserve exact case/assertion counts. The original-source16-case run reproduced14 failures /324 assertions /zero errors, with two compatibility controls passing. These are regression outcomes, not14 distinct vulnerability claims.

The initial16-case candidate passed16/749. A subsequent transient storage deletion exception containing a concurrency-shaped message reproduced one failure /3 assertions /zero errors; the fixed-message storage wrapper then made the final17-case matrix pass. The storage error now stops before another put even when its original message resembles a database retry. These controlled exceptions and explicit PDO commits are not real contention or lost-acknowledgment simulations.

Scoped PHP syntax/read-only Pint and unchanged100-route/32-table safe CLI pass. Token-normalized comparison preserves19 existing methods and permits only storePreview plus one private cleanupRolledBackPreviewArtifact helper; the current Event/active administrator/Session prefix and Guest/child/status processing remain intact. All362 unrelated non-document baseline paths and354 frozen tested-source paths match, including environment/app key, Git index and existing builds. No migration, route, grant, admission algorithm, configured data/account/ownership/service, dependency/build or deployment change.

This repair partly mitigates R32 for known root callback rollback/retry only. Current authority loss leaves earlier files untouched; unsuccessful cleanup leaves the artifact outcome unresolved for separate reconciliation. The injected false/throwing deletion fixtures fail before unlink and retain bytes by construction. An adapter may act before returning false or throwing; no general byte-retention or undo guarantee follows. A successful callback followed by a commit-class error leaves its earlier artifact unmarked across later callback entry, even when that artifact has no database reference. The inherited final catch still acts on its current attempt and captured disk; same-attempt final/uncertain commit outcomes are not certified safe. Process crashes, lost acknowledgment, durable cleanup-failure reconciliation, enclosing caller rollback, arbitrary external transaction/file/reference manipulation and secure-delete recovery remain open. R33, child recertification and historical/internal return graphs remain separate. SQLite query instrumentation establishes primary/locking/ID-only builder intent, not actual MySQL row-lock behavior; lookup breadth/indexing and engine schedules remain rollout/performance review work. The NULL-disk reference is a synthetic SQLite-only malformed/legacy-schema fixture, not the installed schema. Invitation/RSVP and the common simple UI remain planned; Phase4 remains IN PROGRESS.

[Recovery contract and outcomes](OFFLINE_RETRY_ARTIFACT_RECOVERY.md). Documentation checks retain86 Markdown references and all20 planned UI task IDs; the existing documentation index link is retained.

Documentation verification: 86 Markdown files decode as UTF-8; 1,308 local links resolve, 13 scoped whitespace/fence checks pass and all 20 planned UI task IDs remain unchanged. Provider failures after partial file effects remain explicitly unverified; no general byte-retention guarantee is claimed.


## Task P4-035 — COMPLETE (2026-10-29; design only)

Objective: define the secure-delete byte-phase authority boundary and its explicit failure outcomes before changing irreversible file effects.

Acceptance:

- Inspect both sole HTTP destroy callers, policy/Gate checks, FileStorage helpers and existing autocommit deletion-feedback tests. Retain the observed unlink → metadata UPDATE → audit INSERT order and distinguish normal HTTP commits from enclosing caller transactions.
- Specify a byte-phase guard with canonical original/current Artifact/Session/Event/actor and selected Event, frozen raw path/disk plus resolved fallback disk, primary Event → current active exact administrator → Session → Artifact locks and exactly one attempt around byte access. Avoid retrying unlink and avoid a new transaction around metadata plus audit.
- Preserve retention as the existing global enable flag and historical archived/revoked package/failed-results compatibility; add no age, lifecycle, quarantine or physical-erasure policy. Preserve existing false/throw/missing-path behavior and exact generic HTTP feedback. Late HttpExceptionInterface refusals must be passed through both destroy controllers in future implementation.
- Choose a concrete integration that reuses one byte-deletion algorithm and passes validated current context forward without a default parent reload. Direct helper calls and independent metadata/audit commits must have explicit compatibility. Current authority for later metadata/audit stages remains separate, not silently certified by a byte-phase fence.
- Name shared-path ownership, disk/path aliases, irreversible bytes, byte-phase commit uncertainty and nested callers as limits. A captured unchanged path is not proof that no other resource references the same bytes. A future complete staged design must validate its own written deletion marker before the audit stage; requiring undeleted state again would reject its own metadata write.
- Define meaningful late authority/lineage/path/disk/raw-ID/retention/positive-control tests and retain every existing deletion-feedback assertion. Document the chosen next bounded implementation and remaining dependencies; no application source, runtime file or test/schema change in this audit.
- Preserve all354 tested-source fingerprints and all364 non-doc baseline paths, retaining P4-034's1,901/36,878 backend gate by exact source preservation. Check unchanged100-route/32-table CLI and documentation UTF-8/links/whitespace/UI task status.

Scope: read-only source review, TEMP evidence and documentation only. No application/test/schema/config/environment/index/build, actual data/account/ownership/service, dependency or deployment change. Do not claim source security or file recovery is implemented by this design.

Design verification: current helpers/controllers and existing autocommit tests were inspected and independently reviewed. A whole-operation transaction would change the verified audit-failure outcome: bytes and metadata currently remain changed while audit INSERT fails. The chosen next implementation fences only byte access in one attempt, reuses one byte primitive and returns current context to mutually exclusive provider/direct branches. Provider failure never falls back; success never reloads the default parent/disk or unlinks twice.

No age/archive/status eligibility is added to the existing retention flag. Late403/404 passthrough is required in both destroy methods. Later metadata/audit authority, shared paths/provenance, aliases, irreversible unlink, byte-phase commit uncertainty and caller transactions remain open. [Concrete integration and acceptance](OFFLINE_SECURE_DELETE_BOUNDARIES.md).

All364 non-doc baseline paths and354 retained tested-source fingerprints match, including application/tests/schema, environment/app key, Git index and builds. P4-034's1,901 tests/36,878 assertions are retained by exact preservation; no new full backend suite was run. Read-only100-route/32-table CLI is unchanged. P4-035 is COMPLETE as documentation/design only; R33, actual rollout and the planned invitation/RSVP/common simple UI remain open.

Documentation verification: 87 Markdown files decode as UTF-8; 1,327 local links resolve, 14 scoped whitespace/fence checks pass and all 20 planned UI task IDs remain unchanged.


## Task P4-036 — COMPLETE (2026-10-29)

Objective: fence secure-delete private-byte access with current legacy authority while preserving the declared metadata/audit order and direct-helper compatibility.

Acceptance:

- Add LegacyOfflineSecureDeletion::delete(Package|ResultImport, actor, explicit selected Event, reason). Capture strict canonical original/current Artifact PK/Session FK, selected Event, Import Event and actor identity before queries; freeze exact raw path/disk and actual fallback disk. Reject malformed caller context before SQL or bytes.
- Supply a trusted optional Closure byte-context provider to the two existing FileStorage composed helpers. Provider and direct branches are exclusive. Provider success returns current resource/Session/actor/path/disk after byte access; never reload an ordinary parent, recalculate disk or unlink twice. Provider failure propagates without fallback. Default direct helpers retain their named composition/outcomes.
- Extract one shared deleteEncryptedArtifactBytes primitive retaining existing private path/disk, missing-file and false/throw behavior. Recheck the existing global retention enable flag at this shared byte point, including after earlier entry checks; no new age/archive/lifecycle policy.
- The protected provider uses exactly one default-connection transaction attempt: primary selected unowned Event → current exact active administrator → captured Session → Artifact. Require current raw PK/FKs, unchanged path/disk and undeleted state; preserve platform/member-admin eligibility and historical archived/revoked packages/failed results. No caller relation substitution or retry around unlink.
- Pass validated current context into the existing metadata UPDATE then audit INSERT outside the byte transaction. Preserve normal autocommit metadata-failure bytes-only and audit-failure committed-metadata outcomes; preserve enclosing caller behavior explicitly. Metadata/audit current authority is not completed by this task.
- Route both destroy actions through this service with explicit selected Event. Pass HttpExceptionInterface403/404 through before existing RuntimeException catches, preserving request validation, success redirects and generic storage/SQL feedback.
- Reproduce old HTTP failures before source installation, excluding helper-only future-class cases. Verify late ownership/actor/Session/Artifact/path/disk/deleted-state/retention changes, no private file access on refusal, primary order and current provider context, one unlink, ordinary/platform/member and historical controls, frozen fallback disk, missing paths, raw caller refusal and one-attempt concurrency-shaped storage failure. Preserve all prior assertions and autocommit failure evidence.
- Run boundary, existing deletion/import compatibility, focused/full isolated regression, syntax/read-only Pint, unchanged100-route/32-table CLI and source/environment/index/build/docs checks. No source schema or configuration change; test-only memory fixtures stay guarded.

Scope: one new scoped tenancy service, the two FileStorage composed methods plus one shared byte primitive/imports, both destroy actions/imports, one new independent regression class and docs. No migration, new route, identity/grant/admission algorithm, actual data/account/ownership/service, dependency/build/environment or deployment change.

Limits: byte-phase commit failure after unlink is irreversible and receives no retry/restoration guarantee. Later metadata/audit authority, shared-path/provenance, filesystem aliases, external writers, partial adapter effects, caller transactions, forensic erasure and durable recovery remain open. A one-attempt byte fence is not complete secure-delete or tenant isolation. Invitation/RSVP and common simple UI remain in their planned phases.

### IMPLEMENTED — P4-036 verification and limits (2026-10-29)

P4-036 routes both existing destroy actions through LegacyOfflineSecureDeletion::delete with explicit selected Event and canonical original/current Artifact, Session, Import Event and actor identities captured before queries. It freezes exact raw path/disk and the resolved fallback disk before the composed-helper hook. Its trusted optional byte-context provider uses exactly one default-connection transaction attempt: primary selected unowned Event → current exact active administrator → captured Session → Artifact. SQL namespace predicates constrain unowned Event, Session Event and Artifact parent/Import Event before full model hydration; raw post-hydration identity/parent/path/disk checks remain. Undeleted state is checked before bytes.

The shared FileStorage deleteEncryptedArtifactBytes primitive preserves private path/disk, missing-file and false/throw behavior and intentionally rechecks the existing global retention enable flag at the byte point. The provider performs the byte phase and returns current resource/Session/actor/path/disk. Provider and direct branches are exclusive: no ordinary parent reload, fallback on provider error, disk recalculation or second unlink follows provider success. Without a provider, trusted direct helpers retain their existing composition and named outcomes, with the added shared byte-point retention recheck. No age, archive or lifecycle prerequisite is introduced.

Only the byte phase is fenced here. The existing metadata UPDATE → audit INSERT → final fresh() tails remain outside the new byte transaction and unchanged. Current authority for those later writes is not implemented by P4-036. Under normal HTTP autocommit, metadata refusal still loses bytes without metadata/audit, and audit refusal still loses bytes while retaining committed metadata. An enclosing caller transaction still changes SQL commit/rollback scope; unlink remains irreversible. Both destroy actions now pass late HttpExceptionInterface403/404 through before their RuntimeException catch; success/validation behavior and generic storage/SQL feedback remain. No retry around unlink is added.

The independent LegacyOfflineSecureDeletionBytesTest passes **36 cases / 846 assertions**. Existing deletion compatibility passes **17/353** with all prior failure-outcome fields and assertions intact; import workflow **11/189**, focused **836/16,380** and full **1,937 tests / 37,724 assertions** pass with zero errors, failures or skips. All86 prior classes retain exact case/assertion counts.

The original-source HTTP-only run reproduced **30 failures / 156 assertions / zero errors in30 cases**. Four direct future-service identity cases and two direct-helper controls were excluded from that original-source filter. Some failures are newly required context/transaction observations, not30 distinct vulnerability claims. The fixed matrix checks both artifacts for late ownership/actor/Session/Artifact/path/disk/deleted-state/retention changes, no byte access on refusal, current provider context, ordinary/platform/member administrators, historical archived/revoked packages and failed results, missing file/path, frozen fallback disk, no double unlink, malformed direct service context and one-attempt concurrency-shaped storage failure.

Five scoped PHP syntax/read-only Pint checks and unchanged100-route/32-table safe CLI pass. Token-normalized comparison preserves28 existing methods, permits four existing method changes and adds the shared deleteEncryptedArtifactBytes primitive; the metadata/audit/fresh tails are identical. One scoped tenancy service and one independent test class are added. All361 unrelated non-document baseline paths and356 frozen tested-source paths match, including environment/app key, Git index and existing builds. No configured retention flag/configuration, migration/schema, route, grant, admission algorithm, actual data/account/ownership/service, dependency/build or deployment was changed.

R33 is partly mitigated only for current authority at the named secure-delete byte phase. Later metadata/audit current authority and the inherited final fresh() graph remain separate. Shared-path ownership/provenance, path aliases, historical encrypted-content attribution, arbitrary external writers, partial adapter effects, byte-phase commit uncertainty, caller-owned transactions, forensic erasure and durable file/SQL recovery remain open. A false/throwing adapter may already have changed bytes; the injected storage faults retain bytes by construction and establish no general undo guarantee. The single attempt prevents framework retry around unlink but cannot restore a file or prove an uncertain commit outcome. SQLite observations establish the named transaction/builder intent and conservation cases, not actual MySQL row-lock waits or durable storage. R32's remaining preview commit/crash/reconciliation limits also stay open. Invitation/RSVP and the common simple UI remain planned; Phase4 and actual rollout gates remain IN PROGRESS.

[Secure-delete contract and outcomes](OFFLINE_SECURE_DELETE_BOUNDARIES.md). The documentation inventory stays87 Markdown files and all20 planned UI task IDs are retained.

Documentation verification: 87 Markdown files decode as UTF-8; 1,331 local links resolve, 14 scoped whitespace/fence checks pass and all 20 planned UI task IDs remain unchanged.


## Task P4-037 — COMPLETE (2026-10-29; design only)

Objective: define current-authority and receipt rules for the secure-delete metadata and audit stages after the implemented byte-only fence, without changing runtime behavior in this design task.

Acceptance:

- Inspect the current FileStorage composition, LegacyOfflineSecureDeletion service, byte regression observers and existing autocommit failure tests. Keep bytes, metadata and audit as independent stages with no new outer transaction or repeated unlink.
- Define trusted optional metadata/audit context providers around the existing write bodies. Each protected SQL stage uses one current primary Event → active exact administrator → Session → Artifact transaction attempt, selected/captured raw lineage and frozen actual path/disk. Preserve existing direct-helper composition when providers are absent.
- Capture the asset identity needed to prevent marking a replaced file (declared UUID/export identifiers, raw path/disk, hash/size and parents), without treating unrelated lifecycle changes as new retention criteria. Retention remains the byte-point enable flag; later writes record the completed authorized byte phase and do not independently require the flag to stay enabled.
- Metadata must begin from the expected undeleted state, invoke the existing update body once on current models, then obtain a primary persisted receipt before commit. A cancelled/false update or invalid persisted marker must not lead to an audit/success. Define receipt fields and type/precision handling rather than invent a persisted operation token.
- Audit must validate the same operation's persisted deletion marker and asset context, not require secure_deleted_at to be null again. The receipt is a correlation check within this closed invocation, not proof against identical arbitrary external writes. Current ownership/actor/parent/asset/marker drift must refuse without new bytes or unauthorized SQL effects.
- Preserve named normal-autocommit metadata-before-SQL and audit-INSERT failure outcomes. Explicitly name new transactional model-callback/commit failure windows; do not describe every legacy error as unchanged. Caller transactions and irreversible-byte recovery remain open.
- Specify compatibility for the old byte fixture: forward added optional provider arguments using a broader variadic fixture signature and observe byte transactions only, preserving all existing assertions/counts. Explicitly plan the HTTP feedback test's SQL-attempt observation change from level0/PDO false to protected level1/PDO true, preserving its partial-effect/private-feedback assertions, request start/end autocommit0 and case/assertion counts. Add direct-helper level0 compatibility controls separately; do not bypass HTTP providers. No fixture edits or tests execute in this design task.
- Obtain an independent read-only review and define the next bounded implementation/test matrix. Preserve every356 tested-source fingerprint and all366 non-doc baseline paths, retaining P4-036's1,937/37,724 backend gate by exact preservation. Verify unchanged100-route/32-table CLI and documentation.

Scope: read-only design review, TEMP evidence and documentation only. No application/test/schema/config/environment/index/build, real data/account/ownership/service, dependency or deployment change. R33 does not become a general restoration, provenance, filesystem or commit guarantee.

### DESIGN VERIFIED — P4-037 later-write protocol (2026-10-29)

P4-037 completes a read-only design for later secure-delete metadata and audit authority; it implements no runtime guard. The next bounded source task is planned to keep immutable operation-local scalar asset context from authorized bytes and use separate exactly-one-attempt current-primary Event → active exact administrator → Session → Artifact write stages. Each optional trusted provider receives current context and one write callback, propagates failure without an unguarded fallback and never repeats byte access or encloses the whole helper in a transaction. Default direct-helper composition remains the compatibility branch.

The planned metadata stage requires matching immutable asset identity and expected undeleted state, invokes the existing update body once on current models, requires its actual strict true result and revalidates current authority plus primary persisted asset/marker before commit. Locks do not prevent this connection's own callbacks from changing authority or asset fields. The asset declares class/canonical IDs and parents, UUID/export identifiers including results source-package export identity, raw path/disk, actual fallback disk, hash and size. The receipt records actual persisted deletion timestamp, canonical deleter, exact reason and the declared asset and is published only after transaction success. Nullable values/types stay explicit; no timestamp precision is guessed from the application clock and no schema token is invented. Cancelled/false updates or invalid persisted markers must not reach audit/success.

The planned audit stage revalidates current authority, the same asset and this invocation's persisted metadata marker in its own single attempt, then revalidates authority/asset/marker again after callback execution before commit. It must persist and verify a newly stored audit row whose action is exactly FILE_DELETED and whose full existing audit metadata plus Event/Session/actor/reason match the immutable expected asset/context, including nullable types; an unsaved model returned after cancelled create is insufficient. Post-callback Artifact/parent rereads use captured immutable class/IDs, and expected receipt/audit values never come from callback-mutated models. It does not require secure_deleted_at to be null again and performs no metadata or byte rewrite. Retention remains the byte-point enable rule: a later flag change alone must not prevent recording the completed authorized byte phase.

Independent read-only review and the concrete planned protocol are recorded for P4-037. All366 non-document baseline paths and356 retained tested-source fingerprints match, including application/tests/schema, environment/app key, Git index and builds. P4-036 remains the latest implemented source gate at **1,937 tests / 37,724 assertions**, retained by exact preservation; no new backend suite or fixture run is claimed. The existing100-route/32-table read-only CLI remains unchanged. P4-037 is COMPLETE as design/documentation only.

The next implementation must preserve the named normal-autocommit metadata-before-SQL and audit-INSERT refusal outcomes, while explicitly testing new transactional callback/commit windows. A model callback that fails after a SQL write may now roll back that stage's SQL; this is not a claim that every legacy failure is unchanged. Bytes remain irreversible. The receipt correlates this closed invocation with persisted state, not an unforgeable operation ledger: identical same-precision arbitrary rewrites, shared paths/provenance, aliases, triggers/external writers, caller transactions, final fresh() graphs, partial adapter effects, commit uncertainty, forensic erasure and durable recovery remain open. R33 remains only partly mitigated by the implemented byte fence. Invitation/RSVP and the common simple UI remain planned; Phase4 and actual rollout remain IN PROGRESS.

The planned P4-038 implementation will narrowly update existing HTTP SQL-attempt observations from level0/PDO false to level1/PDO true for the new protected write stages. Requests must still start/end at actual autocommit0; every existing partial-effect/private-feedback field, assertion and case count remains exact. Separate new direct-helper controls must establish level0 composition; no HTTP provider bypass is allowed. The old byte fixture forwards new optional providers with a broader variadic signature and filters its transaction observer to byte stages, retaining its existing assertions/counts. No fixture was edited or executed in P4-037.

[Planned protocol and test matrix](OFFLINE_SECURE_DELETE_BOUNDARIES.md).

## Task P4-038 — COMPLETE (2026-10-30)

Objective: implement the P4-037 reviewed later-write protocol for protected legacy offline secure deletion, preserving independent byte/metadata/audit stages and the declared outcomes.

Acceptance:

- Append optional trusted metadata/audit context providers to both FileStorage secure-delete helpers. Reuse their existing metadata and audit bodies as one-shot callbacks; propagate provider failure without direct fallback, duplicate writes, parent reload, disk recomputation or another byte operation. Preserve no-provider direct composition.
- Extend LegacyOfflineSecureDeletion with one reusable current primary Event -> active exact administrator -> Session -> Artifact guard and three separate exactly-one-attempt stage transactions. Preserve strict captured original/current IDs, selected Event, namespace SQL predicates and raw postchecks. No new outer transaction or retry around irreversible bytes.
- Capture immutable invocation-local scalar asset context at the authorized byte point: class/IDs/parents, raw path/disk, frozen actual disk, raw nullable hash/size and Package UUID or Import UUID/results/source-package identities. Later providers must not derive expectations/lookup IDs from mutable callback models.
- Metadata validates asset and undeleted state, invokes the original update once, requires strict true, revalidates authority/asset using current primary locks and obtains the actual persisted deletion timestamp/canonical deleter/exact reason before commit. Publish its scalar receipt only after successful transaction return. Cancelled/false or invalid persisted marker cannot reach audit/success.
- Audit validates current authority plus the same asset and actual written marker (not undeleted state), invokes the original audit once, then rechecks authority/asset/marker and a newly persisted canonical audit identity with matching action/Event/Session/actor/reason and complete existing asset metadata. Returned unsaved models or flags alone are insufficient. Do not add an audit payload field, operation token/schema or history scan.
- Retention remains the existing entry/byte enable flag, not a later-write veto or new age/status policy. Preserve historical package/results eligibility, captured disk, missing-file behavior and the named normal-HTTP metadata-before-SQL/audit-INSERT partial outcomes. Explicitly test new protected after-write callback rollback outcomes; bytes remain irreversible and caller/commit uncertainty stays open.
- Adapt the existing byte fixture mechanically to forward later provider arguments and observe only byte starts/prefixes, keeping all36 cases/846 assertions. In existing feedback tests change only the declared SQL-attempt expectation from level0/PDO false to protected level1/PDO true; retain all cases/counts, partial-effect/private-feedback assertions and request start/end autocommit0. No HTTP provider bypass. Add direct-helper level0 controls in the new class.
- Add one independent SQLite :memory:/actual-PDO/autocommit0 guarded class with unique TEMP fake storage: authority/lineage/asset drift after bytes and after metadata commit, scalar snapshots versus mutable models, marker drift, cancelled writes, wrong persisted marker/audit payload, post-write exceptions/own-callback authority drift, current-context/ordinary-platform-member/nested positives, nullable asset/missing path/file, retention disabled after bytes and no repeat unlink. Reproduce relevant original-source behavior before installing app candidates.
- Preserve all87 prior test classes' exact case/assertion counts subject only to the explicitly revised feedback expected context. Run new boundary, byte/deletion compatibility, workflow, focused/full isolated suites, scoped syntax/read-only Pint, unchanged100-route/32-table safe CLI, source/environment/app-key/index/build and87-doc/20-planned-UI verification. No source edits after final tested freeze without rerunning affected gates.

Scope: two existing app services, one new independent regression class and mechanical changes in two old test classes, plus docs/TEMP evidence. No controller/route/schema/config/identity/grant/admission change, actual data/account/ownership/service, dependency/build/environment or deployment operation.

Limits: receipts correlate this closed invocation, not unique file provenance or an unforgeable operation ledger. Shared paths/aliases/external reference manipulation, identical marker rewrites, arbitrary malicious callbacks/triggers, partial adapter effects, byte or SQL commit uncertainty, caller rollback, final fresh() read certification and durable recovery remain separate. Invitation/RSVP and all-interface simple UI stay in planned phases.

### IMPLEMENTED — P4-038 verification and limits (2026-10-30)

P4-038 now protects legacy secure deletion as three separate exactly-one-attempt default-connection stages: bytes, metadata and audit. A reusable current-primary Event → exact active administrator → Session → Artifact guard retains SQL namespace predicates and raw identity/lineage/target checks. Captured scalar class/IDs and raw path/disk/fallback never come from callback-mutated models. Provider/direct branches remain exclusive; no outer transaction, repeated unlink, write fallback, ordinary parent reload or disk recalculation is added.

The authorized byte stage captures immutable scalar asset context, including raw nullable hash/size, Package UUID or Import UUID/results/source-package export identities. Metadata validates that asset and expected undeleted state, invokes the original update once and requires strict true. It repeats current authority/asset checks after callbacks, reads the actual primary persisted deletion timestamp/canonical deleter/exact reason and publishes the scalar receipt only after successful transaction return. Audit validates that asset and this marker, invokes the original audit once, repeats current authority/asset/marker checks and verifies the returned candidate's canonical insertion identity plus an actual primary locked row with exact FILE_DELETED action, Event/Session/actor/reason and complete existing metadata. Unsaved/cancelled creates, wrong database-only marker/payload and older matching rows cannot substitute for the completed write within the declared trusted callback flow.

Retention remains the entry/byte enable flag; disabling it after authorized bytes does not independently veto recording that phase. Protected stages retain ordinary/platform/member administrators, historical package/result eligibility, missing bytes and captured disks. No-provider helpers retain their trusted direct composition and SQL level0/PDO false. Existing metadata UPDATE and audit INSERT bodies are reused unchanged, and the final fresh() remains a separate read boundary.

LegacyOfflineSecureDeletionWritesTest passes **91 cases / 3,683 assertions**, including four direct-helper SQL controls; 87 HTTP cases exercise the protected contract. Byte compatibility **36/846**, deletion compatibility **17/353**, workflow **11/189**, focused **927/20,063** and full **2,028 tests / 41,407 assertions** pass with zero errors, failures or skips. All 87 prior classes retain exact case/assertion counts, subject only to the declared protected HTTP SQL-attempt expected-context refinement.

The final original-source HTTP run reproduced **87 failures / 530 assertions / zero errors in87 cases**. Four direct controls are excluded from that filter. These include newly required transaction/current-context observations, not87 distinct vulnerability claims. The retained initial90-case draft separately produced86 HTTP failures/524 assertions; it is not the final reproduction gate. The final matrix checks post-byte/post-metadata authority, lineage, asset and marker drift; scalar receipts against mutable models; cancellations; database-only wrong marker/audit payload; concurrency-shaped after-write exceptions; own-callback authority changes; current three-stage context; nested/ordinary/platform/member, retention-off, missing/nullable and actual stored-timestamp positives. The stored-timestamp control verifies acceptance of actual persisted representation rather than an application-clock reconstruction.

Source verification preserves 68 existing methods and 115 old assertion calls, permits only the eight declared app/test method adaptations, and confirms the original write bodies unchanged. Byte fixture forwarding/byte-only transaction observation retains36/846. Existing feedback changes only protected HTTP SQL-attempt level0/PDO false to1/true; request start/end autocommit0, exact partial-effect/private-feedback fields and all old case/assertion counts remain. All 362 unrelated non-document baseline paths and 357 frozen tested-source paths match, including environment/app key, Git index and builds. Syntax/read-only scoped Pint and unchanged100-route/32-table safe CLI are separately recorded; no controller, route, schema/migration, configured data/account/ownership/service, configuration, dependency/build or deployment change is claimed.

Normal HTTP metadata-before-SQL refusal still leaves bytes gone, no metadata marker and no audit. Audit INSERT refusal still leaves bytes gone plus committed metadata and no audit. Protected after-write callback failures now roll back their own SQL stage: metadata callback failure can undo its marker and callback SQL, while audit callback failure can undo its audit row/callback SQL and retain the preceding marker. These are intentionally changed callback windows, not a claim that every legacy error is unchanged. Each stage has one attempt even for a concurrency-shaped exception; unlink remains irreversible.

R33 is partly mitigated for the named current-authority and persisted-write boundaries, not resolved as file/SQL recovery or complete tenant isolation. Receipts correlate this closed invocation; they are not an unforgeable ledger or unique filesystem ownership proof. Shared paths/provenance, aliases, identical marker rewrites, forged insertion flags/substituted historical models, arbitrary malicious callbacks/triggers or external writers, partial adapter effects, byte/SQL commit uncertainty, caller rollback, forensic erasure, final fresh() graph isolation and durable reconciliation remain open. Persisted timestamps are accepted from actual database state; this does not establish semantic validation of every arbitrary injected date string. Nullable hash/package-size controls deliberately loosen only their guarded SQLite :memory: fixture columns; they do not describe the installed NOT NULL schema or change an application migration. SQLite fake-file/builder observations establish named cases, not actual MySQL lock waits or real storage durability. P4-034's remaining preview recovery limits stay open. Invitation/RSVP and the common simple UI remain planned; Phase4 and actual rollout remain IN PROGRESS.

[Current secure-delete contract](OFFLINE_SECURE_DELETE_BOUNDARIES.md). Documentation remains87 Markdown references and20 planned UI task IDs.

## Task UI-REF-001 - COMPLETE (2026-10-30; documentation and measurement only)

Objective: merge the newly approved 39-screen PangaEvents visual specification into the existing migration without restarting phases or changing production UI/backend behavior.

Acceptance:

- Read the canonical/design/journey/locale/portal/scanner/studio contracts; inventory and individually inspect all 39 original PNGs. Preserve their exact bytes/names and record hashes, dimensions, pixel measurements, framing exclusions and normalization uncertainty. Do not invent missing reference assets or claim exact font/CSS metadata recovered from raster.
- Maintain the four specialist implementation/screen/component/visual-QA documents and durable measurement evidence. Map every reference to actual route/view or missing backend, proposed target, components, authentication, status and independent QA outcomes. Existing legacy analogs do not authorize customer exposure.
- Make the approved PNGs authoritative for PangaEvents appearance while retaining existing backend/security architecture and simple task completion. Document necessary locale, capacity, statistical, shared-identity, provider, ticket-release, price and accessibility corrections.
- Add the exact A-H implementation batches to pending owning phases and retain all 20 existing UX task IDs/statuses. No screen is implemented in this integration; every approved-design screen remains NOT STARTED and all rendered/functional checks remain NOT RUN.
- Verify all 39 originals, all 406 non-doc baseline paths and 357 retained P4-038 tested-source paths. Retain2,028 tests/41,407 assertions by exact source conservation; no new full backend suite is needed for documentation only. Check all Markdown links/UTF-8/whitespace/fences,39-row/status/reference coverage and20 prior UX tasks.

Scope: documentation and TEMP measurement/integrity evidence only. No UI code/token/style, route/controller/service/test/schema, configured data, credentials, actual message/payment/device, dependency/build or deployment change. Phase 4 authorization remains active; public signup/customer event exposure, later UI/Android/billing/provider and real-data gates stay open. Continue the next bounded backend task after this additive integration is verified.


### Approved reference batches - NOT STARTED

These additive tasks retain all earlier phase/UX statuses. Full batch behavior may span its existing dependencies; no batch is authorized early by this inventory.

| ID | Batch / screen IDs | Existing phase dependencies | Status |
|---|---|---|---|
| REF-UI-A | Entry/auth:01,02,03,10,11 | P6/P7; P2 identity preserved; P10 signup | NOT STARTED |
| REF-UI-B | Events:04,05,12,20,30 | P8/P9/P6; P17/P19/P24 handoffs | NOT STARTED |
| REF-UI-C | Invitations:06,13,14,21 | P16/P17/P18; P21-P23 template acceptance | NOT STARTED |
| REF-UI-D | Guests:07,15,16,22 | P5/P6/P9; P19/P24 later states | NOT STARTED |
| REF-UI-E | Distribution:17,18,27,31,32 | P19/P20/P24 | NOT STARTED |
| REF-UI-F | Guest RSVP/ticket:08,09,33,34 | P16/P18/P19; existing QR/admission contracts | NOT STARTED |
| REF-UI-G | Scanner:19,23,24,25,35,36,37 | P6/P12/P13; P14/P15 only when native scope/source available | NOT STARTED |
| REF-UI-H | Reports/settings/team/plans:26,28,29,38,39 | P5/P6/P9/P11/P12/P19/P24/P25 | NOT STARTED |

[Implementation and measured evidence](REFERENCE_UI_IMPLEMENTATION.md) - [39-screen matrix](REFERENCE_UI_SCREEN_MATRIX.md) - [components](REFERENCE_UI_COMPONENT_MATRIX.md) - [visual QA](REFERENCE_UI_VISUAL_QA.md).

UI-REF-001 verification: all 39 required PNGs were individually inspected and mapped, all original hashes match, all 406 non-document baseline paths and 357 retained tested-source paths are exact. The separate P4-038 backend gate remains 2,028 tests / 41,407 assertions by conservation; no backend suite was rerun for documentation only. All 39 approved-design screens and A-H batches remain NOT STARTED, with rendered/functional checks NOT RUN. The four specialist documents and durable measurement evidence are recorded in docs; no production UI or backend feature is claimed.

## Task P4-039 - COMPLETE (started 2026-11-01; completed 2026-11-02)

Objective: protect the two existing legacy single-Guest metadata update/cancel transactions with current authority and conserve already-used entries during a late allowance change. This advances Phase4 before customer event exposure, without starting Phase5 extraction or approved-reference UI implementation.

Dependencies: completed P4-038 source gate (2,028 tests / 41,407 assertions), completed UI-REF-001 reference integration, PositiveIntegerId/legacy administrator conventions, current Guest model/pass rules and existing OfflineEventLockService. Real-data/restore/ownership/deployment gates remain open.

Acceptance:

- Add one scoped legacy Guest mutation service with a trusted callback API, proposed mutate(Guest, User, explicit selected Event, Closure):void. Capture canonical original/current Guest PK/Event and actor identity plus selected Event before the new guard's SQL; require Guest parent equals selected Event. Freeze these scalar identities across the existing three transaction attempts. Invalid/mismatched raw caller context refuses before the guard queries; no permissive integer coercion or fallback Event selection.
- Move/reuse the existing update/cancel transaction composition through that service without adding an outer/nested transaction. Preserve exactly the existing three-attempt setting. Inside each callback lock current primary selected unowned Event, current exact active administrator, invoke existing OfflineEventLockService::assertEventAdmissionDataEditable(currentEvent,true), then primary-lock the exact Event-scoped Guest and raw-check PK/Event before the trusted write callback. The reused lock service retains its Event reread/blocking-Session query; document this rather than falsely claiming only four SQL reads.
- Preserve ordinary/platform/member administrator eligibility only when current role is exactly admin and active. Retain historical Event status compatibility; add no new lifecycle/retention/platform bypass. Use no retained GuestAccess boolean, stale actor/Guest or later selected-session resolution as write authority.
- Integrate only AdminController::update and ::cancel (plus necessary import/signature if required). Keep initial entry checks, request validation, field allowlist, phone normalization, Single/Double/Special mapping, redirects and messages. Do not alter the global ensureGuestBelongsToSelectedEvent helper for other actions. Prefer preserving cancel's current method signature and explicitly passing request()->user() to the service.
- Before update on the current locked Guest, repeat the existing normalized allowance >= current used_entries rule with the existing allowed_entries validation message. Freeze the already validated payload; do not re-read mutable request data during retries. This prevents the model's existing clamping from reducing legitimate admissions. Do not modify Guest::applyPassRules, counters/history, manual codes, QR associations/tokens/images or admission algorithms. Cancellation retains its existing status-only business write.
- Preserve the existing OfflineEventLockedException browser/JSON behavior, including a blocking session appearing after entry validation, and existing SQL rollback/retry behavior. No new file operation, audit/notification/provider effect or generic error-handling redesign. Callback/commit/caller/external-writer guarantees beyond the named current-authority scope are not implied.
- Add one independent actual SQLite :memory:/PDO/autocommit0-guarded regression class with unique TEMP fake storage and whole table/file snapshots. Reproduce original HTTP behavior before installing app candidates. Cover late actor role/activity changes, Event ownership, Guest reparenting/selected-context retargeting, current used_entries increasing after initial validation, current primary/lock order and scoped Guest reads, ordinary/platform/member and recorded nested controls, blocking offline lock, raw malformed/original-current identity refusal before SQL, a valid PHP_INT_MAX identity control, and real partial-write rollback. Use a focused meaningful matrix rather than exhaustive duplicate permutations.
- Preserve all88 existing test classes' case/assertion counts and existing Guest/offline-lock assertions. Run boundary, Guest management/offline compatibility, relevant focused/full isolated suites, syntax/read-only Pint, unchanged100-route/32-table safe CLI, source/environment/app-key/index/build and documentation checks. Protect all39 original reference hashes,20 prior UX rows,8 reference batches and39 NOT STARTED screen statuses. No source edits after tested freeze without rechecking affected gates.

Scope: one new tenancy service, the two existing AdminController methods/import, one independent test class and affected docs/TEMP evidence. No global model/lock service, other controller action, view/style/localization, route/schema, customer signup/event exposure, identity/grant, actual data/account/ownership/service, dependency/build/environment or deployment change.

Limits: entry/read-only/bulk/create/delete/import/export/report/QR/file actions remain separate; this task is not complete tenant isolation or a shared AdmissionService/GuestService implementation. Existing model callbacks remain trusted composition; current pre-write locks do not certify arbitrary malicious callbacks, external connection manipulation, enclosing caller rollback or unknown commit outcomes. SQLite observes builder intent/conservation, not actual MySQL lock schedules. All39 approved UI screens remain planned in their owning phases.

### IMPLEMENTED — P4-039 verification and limits (2026-11-02)

LegacyGuestWrites composes only existing AdminController::update/cancel transactions through one trusted callback API. Canonical raw original/current Guest PK/Event and actor IDs plus explicit selected Event are checked before guard SQL and frozen across unchanged three attempts. Each attempt locks primary current unowned Event → current exact active administrator → unchanged OfflineEventLockService → exact Event-scoped Guest/raw postcheck. The reused helper contributes its own primary Event reread and blocking-Session query, making five guard reads, not four. Ordinary/platform/member administrators and historical archived/cancelled Event controls remain compatible without a bypass.

Initial entry checks, validation, phone normalization, Single/Double/Special mapping, allowlists and redirect/message behavior remain. The frozen update payload repeats the same allowed_entries floor/message against current locked used_entries; cancellation still supplies only status=cancelled. Guest model/pass/status/admission behavior, counters/history, manual codes and QR associations/tokens/images are unchanged. No outer transaction or general admission/Guest service extraction is added.

Independent LegacyGuestWritesTest passes **38 cases / 727 assertions** at actual SQLite :memory:/PDO/autocommit0 with unique TEMP fake storage and whole-table/file snapshots. Existing Guest/offline compatibility passes **16/132**, focused **974/20,855** and full **2,066 tests / 42,134 assertions**, with zero errors/failures/skips. All **88 prior classes** retain exact case/assertion counts. Cases cover late actor/ownership/Guest-parent changes, selected/request retargeting, increased current admissions, five primary locking reads/scoped Guest SQL, ordinary/platform/member/nested and historical-Event controls, late offline browser/JSON feedback, raw identity refusal before SQL/transaction, PHP_INT_MAX, real partial-write rollback and exactly three failing SQL attempts with frozen context/payload.

Final original-source HTTP reproduction is **30 cases / 241 assertions / 22 failures / zero errors**; eight direct future-service cases are excluded. The separate retained initial-red-38draft HTTP run had 30/239 with 20 failures/two ModelNotFoundException errors. The final retry test asserts the actual QueryException type, so the old retargeted lookup is an assertion failure instead of an unclassified wrong-exception error; named scope remains unchanged. These are regression outcomes, not 22 separate vulnerability claims.

Syntax/read-only scoped Pint and unchanged 100-route/32-table safe CLI pass. Only two controller methods and one import change; the other **89 methods** retain exact tokens and remaining controller bytes are identical. All **405 unrelated non-document baseline paths** and **359 frozen tested-source paths** match, including environment/app key, Git index and existing builds. Five reference UI documents and 39 original PNGs are exact; 20 prior UX rows, eight reference batches and 39 NOT STARTED screen statuses remain unchanged. Documentation has 92 Markdown references after this addition. [Detailed contract](LEGACY_GUEST_WRITES.md).

This is a named pre-write authority boundary. Trusted model/write callbacks remain trusted; update() cancellation is not newly detected through a strict return-value or persisted-write receipt requirement. After-write authority mutation, arbitrary callbacks/external connection manipulation, enclosing caller rollback and unknown commit outcomes are not certified. The ambient transaction control retains ordinary rollback behavior. Retry faults use an actual SQLite trigger/PDO failure with a synthetic lock message, not real MySQL contention. Entry/read-only/bulk/create/delete/import/export/report/QR/file actions, shared service extraction, complete tenant isolation and customer event exposure remain separate. Real database/restore/ownership/deployment and approved-reference UI implementation gates stay open; Phase4 remains IN PROGRESS.

## Task P4-040 - COMPLETE (started/completed 2026-11-02)

Bound the four existing legacy Guest/QR metadata actions: activation, deactivation, revocation and restoration. Reuse P4-039 `LegacyGuestWrites` current Event/admin/offline/Guest guard inside the existing three attempts; append a primary locked lookup of the current unique QR associated with that guarded Guest, with canonical raw QR/Guest identity checks. Add `mutateWithQr(Guest, User, mixed selectedEventId, Closure): void`; callbacks receive the guarded Guest and its current optional QR. Preserve the existing `mutate` body.

Keep the same four payloads, preflight checks, redirects, feedback and status hooks. Activate/deactivate still return404 for no QR; revoke/restore still succeed without QR. Deactivate preserves revocation time, activate/restore clear it, and restoration respects current used entries through the existing Guest model. Preserve QR token/manual code/path/capacity/admission history. No QR generation or bytes, deletion/import, schema/routes/UI, new receipts, Phase5 extraction, customer exposure or real database changes.

Dependencies: verified P4-039 (2,066 tests / 42,134 assertions), canonical positive IDs, existing unique QR/Guest schema and offline service. Checks: isolated old-source HTTP regressions, late authority/parent/QR relation changes, primary/scoped/locked lookup, missing-QR and pass-status compatibility, frozen context across retries, real SQL rollback of restore's preceding Guest update, focused/full regression, syntax/scoped Pint and source/docs/reference conservation. Retain trusted-callback/caller/uncertain-commit/MySQL limits. After this family, perform a finite remaining-surface/Phase4 exit audit rather than automatically opening further callback hardening tasks.

### IMPLEMENTED — P4-040 verification and limits (2026-11-02)

P4-040 (completed 2026-11-02) protects the four existing legacy Guest/QR activation, deactivation, revocation and restoration transactions. mutateWithQr delegates to the unchanged P4-039 guard, freezing canonical Guest/Event/actor and selected Event identities through the existing three attempts. Its sixth primary locking read selects the current unique QR by guarded Guest ID and checks raw QR identity/parent; cached caller relations supply no authority. The unchanged offline helper contributes its Event reread and blocking-Session query, so there are six named guard reads in total. No outer transaction or later request/session retargeting is introduced.

All four business payloads and entry/redirect/message contracts remain. Activate/deactivate require a QR; revoke/restore retain success without one. Deactivation preserves revocation time, activation/restoration clear it, and restoration retains usage through existing Guest status normalization. QR token/path, manual code, capacity, counters/history, neighboring rows and bytes remain exact. No QR generation/file/schema/route/UI/customer exposure is added.

Original-source HTTP: 31/240, 18 failures and zero errors; boundary 36/424, existing QR/Guest/scanner/offline compatibility 75/672, focused 1,069/21,819 and full **2,102 tests / 42,558 assertions**. Passing suites have zero errors/failures/skips; all 89 prior classes retain exact case/assertion counts. Five direct future-service cases are excluded from the original-source HTTP filter. The 18 failures include newly required context/order observations, not 18 separate vulnerability claims. Cases cover late authority/parent loss, scoped primary/locked reads, current unique QR association versus a stale cached relation, missing QR/status rules, offline feedback, real partial SQL rollback, malformed raw context and exactly three failing SQL attempts with frozen identities.

Only the four named controller methods change; all 87 other methods retain exact tokens and remaining controller bytes are identical. Existing helper mutate/constructor and bytes outside its added import/method are preserved. All 408 unrelated non-document baseline paths and 360 frozen tested-source paths match, including environment/app key, Git index and builds. All 39 original PNGs, five protected UI references, 20 previous UX rows, eight reference batches and 39 NOT STARTED screen statuses remain unchanged.

Trusted callbacks and update cancellation remain inherited composition; no new persisted-write receipt, after-write authority, caller-rollback or uncertain-commit guarantee is added. SQLite builder/retry observations do not prove actual MySQL lock schedules. Customer event exposure and real-data/restore/ownership/deployment gates remain open. No strict write-return/receipt, arbitrary-callback or complete tenant-isolation guarantee is added. Next: perform a finite remaining-surface/Phase4 exit audit before choosing another source task; do not automatically extend this family into callback or receipt hardening. Phase4 remains IN PROGRESS.

[Contract and limits](LEGACY_GUEST_QR_WRITES.md).

## Task P4-041 - COMPLETE (started/completed 2026-11-02)

Objective: complete a finite Phase4 remaining-surface/exit audit after P4-040. Inspect all remaining legacy resource families once, record current protection and source gaps, map each to its existing phase, and choose one concrete next task with an explicit contract. Keep Phase4 and actual rollout gates open until their evidence exists.

Scope: documentation and read-only source inspection only. Produce a specialist exit register covering create/import/generation/repair, single/bulk deletion, reads/exports/static media and scanner authority, plus the later Phase5 shared contracts. Define source completion separately from actual database/ownership/restore/fencing/hosting/device proof. Retain existing callback/commit limitations without automatically reopening them as separate tasks.

Files: canonical four documents, Phase4 report/tasks, project status, security/risk/test references, docs index and a new PHASE_04_EXIT_AUDIT.md. No runtime, test, schema, provider, asset, dependency, environment, real-data or deployment change. No early UI/customer route or Phase5 extraction.

Acceptance: source-cited finite endpoint/helper register, independent review, exact retained P4-040 source (2,102 tests / 42,558 assertions), unchanged 100-route/32-table evidence, all unrelated non-document files and 39 original PNGs/five reference documents preserved, 20 UX/eight reference-batch/39 screen statuses unchanged, valid UTF-8/local links/formatting. No new backend test run is claimed for documentation-only work.

### VERIFIED - P4-041 audit decision (2026-11-02)

The [finite exit register](PHASE_04_EXIT_AUDIT.md) groups the remaining runtime work once and assigns source versus actual-environment proof. Independent source review retained existing Event/staff entry-actor limits, the no-DML Meta stub, all scanner aliases/log effects and partial-byte deletion semantics. Phase4 is not complete. P4-040 remains the latest implemented source gate; no backend test run, route/schema/UI change or actual-data operation is claimed for this audit.

P4-042 is proposed as one whole-family deletion design/characterization contract. It must decide current bulk set/locks, Guest/QR/path authority, byte/SQL order, one-attempt byte boundaries and missing/false/throw/partial outcomes before implementation. Other finite families stay explicit blockers; shared extraction remains in its existing later phase.

## Task P4-042 - COMPLETE (started/completed 2026-11-02)

Objective: define one complete current-authority/file contract for existing single and bulk Guest deletion before implementation. Inspect destroy, bulkDestroy and deleteGuestPass together with current Guest/QR/offline/storage/history behavior. Use a bounded TEMP-only characterization probe against invented SQLite rows and fake files where needed.

Scope: design, independent review, TEMP characterization and documentation only. Freeze selected/actor/Guest IDs; decide current bulk set/locks, QR/path association, missing/false/throw outcomes, byte/SQL order, one-attempt byte boundaries, retained partial outcomes and compatibility. Do not reuse metadata retries as filesystem rollback. Characterize actual existing cascade/history effects rather than invent retention rules. No application/test-suite/schema/provider/UI/real-data change or Phase5 extraction.

Dependencies: P4-041 finite register, P4-040 retained 2,102 tests / 42,558 assertions and existing SQLite/fake-storage safeguards. Files: a new deletion-design specialist and affected canonical/security/risk/test/status/phase references. Probe files and outputs stay in task TEMP; actual PDO/database/autocommit and guarded TEMP fake storage must be checked before fixture mutations.

Acceptance: named current behavior and supported gaps, concrete service/controller contract with precise retry/partial-outcome policy, finite implementation test matrix, independent source/design review, passing bounded characterization where used, exact retained source/unrelated files/references/statuses and documentation gate. Keep the four-family register open; this design does not implement deletion protection or complete Phase4.

### VERIFIED - P4-042 design and characterization (2026-11-02)

The [deletion design](LEGACY_GUEST_DELETION_DESIGN.md) passed independent review. The TEMP probe passed 12 cases / 168 assertions with 12 unique observations; initial bootstrap-only errors and the Windows cache-prefix fixture correction are retained separately. It demonstrates current behavior, not an installed repair or real MySQL contention. All 360 tested-source paths, 411 unrelated non-document paths, 39 original PNGs and five UI references remain exact; P4-040 retains the 2,102/42,558 source gate without a new main-suite run.

P4-043 is proposed for one dedicated deletion service/fixed operational exception, two controller actions/shared-helper removal, independent regressions and narrow SW/EN failure resources. The contract validates the complete current Guest/QR/path/reference set before bytes, permits one local transaction attempt, distinguishes strict false/cancel failure from success and preserves current FK/scalar behavior. Removed bytes cannot be restored by SQL rollback; callbacks/aliases/caller/unknown commits and real rollout remain explicit limits. This design does not implement protection, complete family 2/Phase4, expose customers or start Phase5/UI work.

## Task P4-043 - COMPLETE (started 2026-11-02; completed 2026-11-03)

Objective: implement the reviewed P4-042 whole single/bulk Guest deletion contract. Scope: LegacyGuestDeletion service, fixed operational exception, destroy/bulkDestroy and removal of their unsafe shared helper, one narrow failure-language helper if useful, SW/EN guest_deletion resources and independent source regressions. Preserve all other Controller methods and existing metadata/admission logic.

Contract: canonical frozen selected/actor/Guest IDs; one local transaction attempt; primary current unowned Event/admin/unchanged offline checks plus the complete ordered Guest/QR/path set and global ID-only shared-reference veto before any bytes/DML. Own byte-then-model deletion with strict reported success, sanitized localized failure and explicit partial bytes/SQL/caller/commit limits. Preserve normal missing/ordinary behavior, success redirects/messages, existing FK/scalar effects and no second Guest/QR/admission system.

Dependencies: P4-042 reviewed design and TEMP 12/168 characterization, P4-040 latest implemented 2,102/42,558 source gate. No schema, provider, QR generation, new routes, real data, dependencies/build, global locale, P6 visuals or deployment. All 39 approved UI implementations remain NOT STARTED.

Acceptance: old-source HTTP regressions before swap; independent candidate review; finite design matrix including raw context, late authority/parents, primary ordered whole-set reads, current QR/path and shared-reference refusal, strict false/throw/cancel, real SQL partial rollback, one attempt, exact cascades/scalars, caller/offline feedback and SW/EN/fallback. Run meaningful boundary/compatibility/focused/full source gates, syntax/scoped Pint, unchanged routes/inventory and complete source/reference/docs preservation. Retain actual MySQL/host/storage/restore and unknown-commit limits.

### IMPLEMENTED - P4-043 verification and limits (2026-11-03)

P4-043 is COMPLETE in source/tests (2026-11-03) for both existing legacy single/bulk Guest deletion actions. A dedicated one-attempt service validates the entire current Event/admin/offline/Guest/QR/path set and all global shared-path vetoes before resolving public storage or performing deletion effects. Strict false/cancel failures now produce sanitized account-language feedback. Known SQL rollback cannot restore removed bytes; caller transactions, arbitrary callbacks, file provenance and unknown commits retain their explicit limits. Full isolated gate: **2,166 tests / 43,324 assertions**. Customer writers/signup, actual rollout and all 39 approved UI implementations remain gated. [Deletion contract and limits](LEGACY_GUEST_DELETION.md).

Original-source HTTP reproduction: **45 cases / 284 assertions / 31 failures / zero errors**; 14 compatibility cases pass, and 19 direct future-service cases are excluded. These are regression outcomes, not 31 separate vulnerability claims. Boundary **64 tests / 766 assertions**, existing Guest/QR/scanner/offline compatibility **149 tests / 1,823 assertions**, focused **310 tests / 5,442 assertions** and full **2,166 tests / 43,324 assertions** (371.062782s JUnit) pass with zero errors/failures/skips. All **90 prior classes** retain exact case/assertion counts. Tests use actual SQLite :memory:/PDO/autocommit guards, unique TEMP fake public storage and whole-table/file snapshots; no configured database or existing application/customer file is changed.

Only destroy/bulkDestroy, their service imports, removal of deleteGuestPass and the narrow guestDeletionFailure helper change in AdminController; its other 88 methods and remaining bytes are preserved. Five reviewed runtime payloads plus one new independent regression class comprise the source change. All 365 frozen tested-source paths and 410 unrelated non-document baseline paths match, including environment/app key, Git index and builds. Syntax/read-only scoped Pint and unchanged 100 routes/32-table safe CLI pass. The 39 original PNGs, five protected UI documents, 20 previous UX rows, eight reference batches and 39 NOT STARTED screen statuses remain exact. Phase4 remains IN PROGRESS.

Both routes use LegacyGuestDeletion and preserve ordinary entry validation/success. Canonical scalar IDs are frozen before SQL; the entire primary ordered current target/reference set is checked before storage resolution. Adjacent PHP_INT_MAX IDs use exact integer ordering. Each accepted target performs its captured byte call once, then ordinary Guest::delete once, each requiring strict true. Fixed operational errors are sanitized before Laravel can misclassify adapter/non-SQL callback lock messages; actual PDO failures retain framework transaction handling. Narrow SW/EN resources use validated account preference with SW fallback and do not change global locale.

Family 2's reviewed source boundary is accepted; create/import/QR generation/repair, reads/exports/static media and scanner authority still need their own complete contracts. Shared-file absence is not provenance, model success is not an affected-row receipt, bytes are not rolled back, and nested real SQL deadlocks/unknown commits/caller retries remain limits. No schema, new audit/receipt, QR-generation/admission change, real data/service/configuration, dependency/build, customer route or approved-reference visual implementation occurred. Actual MySQL/host/storage/restore/ownership and deployment prerequisites remain open. [Detailed implemented contract](LEGACY_GUEST_DELETION.md).

## Task P4-044 - COMPLETE (started/completed 2026-11-03)

Objective: define one whole-family authorization and byte-effect contract for legacy Guest creation/import, explicit/missing QR generation and image/manual-code repair, including their current GET/ZIP callers. Inspect actual source and existing tests; do not install guards during this task.

Scope: read-only source/test/schema inspection, TEMP audit/review/preservation evidence and documentation. Preserve all365 tested-source paths and416 unrelated non-document baseline paths. Retain P4-043's actual2,166 tests/43,324 assertions and100-route/32-table evidence by exact source preservation; no new backend run is claimed.

Decisions: frozen canonical actor/selected/Guest/preview context; current primary unowned Event/active administrator/offline/Guest/current-QR composition; one local attempt for every byte-bearing operation without nested helper retries; explicit rotation versus missing-only versus credential-preserving repair; authorized manual-code persistence and pure rendering; per-row duplicate/partial outcomes, authority stop and session-token limits; exact old/new path/reference/collision/strict storage behavior and irreversible/unknown-commit limits. Ordinary-media serving/migration stays family3; existing Event creation retains its named entry/allowlist scope.

Acceptance: source-line-backed observations, a concrete reusable service/controller contract and finite next-implementation matrix, independent design/document review, UTF-8/local-link/status/source/reference conservation. No application/test/schema/routes/configuration/environment/build/dependency/service/real data/account/owner/deployment change. This design does not complete family1 or Phase4, implement the39-screen UI, open customer features or create another schedule/worker.

### VERIFIED - P4-044 whole-family design and preservation (2026-11-03)

P4-044 completed source-inspection/design documentation only (2026-11-03). The reviewed whole-family contract covers Guest create/import, explicit and missing-only QR generation, and every image/manual-code repair caller. It proposed one local mutation attempt, frozen current authority, distinct rotation/no-op/repair modes, pure rendering, guarded path/reference effects and honest import interruption. At that checkpoint no guard had been installed; family 1 remained OPEN. P4-043 was then the implemented **2,166 tests / 43,324 assertions** gate, retained by source preservation without rerun at that checkpoint. Phase4 remained IN PROGRESS at that checkpoint. [Design and finite acceptance](LEGACY_GUEST_ISSUANCE_DESIGN.md).

All 416 non-document baseline paths and 365 retained tested-source paths remain exact, including environment/app key, Git index and builds. The 39 original PNGs, five protected UI documents, 20 previous UX rows, eight reference batches and 39 NOT STARTED screen statuses are preserved. That task added one specialist document to the previous 97-Markdown inventory. No application/test/schema/route/provider/configuration/service, real database/account/owner, dependency/build or deployment change was made, and no backend suite was rerun. Independent source/design and documentation review plus UTF-8/local-link/status/hash checks gate completion.

At the P4-044 checkpoint, proposed P4-045 was required to implement this family together after its concrete source/test scope is reviewed: LegacyGuestIssuance service and fixed failure outcomes, create/import/generation/repair controller integration, explicit actor/selected/preview context, pure GuestQrImageService rendering and narrow SW/EN feedback. Its finite matrix covers current authority, creation/manual/pass rules, duplicate/import continuation versus interruption, three QR modes, all repair callers, path collisions/references/partial bytes, exactly one local attempt and caller/unknown-commit limits. All 91 existing test classes and their case/assertion counts must be preserved, with old-source observations before swap and meaningful boundary/compatibility/full checks. No implementation task, customer route, ordinary-media migration or Phase5/UI work was opened by that design completion.

## Task P4-045 - COMPLETE (started 2026-11-03; completed 2026-11-04)

Objective: implement the reviewed P4-044 contract for the whole legacy Guest create/import/QR generation and image/manual-code repair family. Preserve completed deletion/metadata/admission behavior and ordinary successful HTTP/export contracts.

Scope: LegacyGuestIssuance service, fixed known-failure/interruption exceptions, pure GuestQrImageService rendering, the named creation/import/generation/repair Controller methods and narrowly shared context/failure helpers, SW/EN guest_issuance labels, one independent guarded regression class, the narrow import-result Blade correction and affected docs. No route/schema/identity/admission/provider, ordinary-media migration, Phase5 extraction or visual implementation change.

Acceptance: capture current515-path baseline/365 tested source before edits; reproduce relevant old-source HTTP failures; independently review exact candidates before installation. Cover canonical frozen identities/current primary authority, creation/duplicate rules, bound/consumed preview, safe partial import, explicit/missing-only/repair modes, pure rendering, all GET/ZIP repair callers, shared/colliding paths and strict one-attempt partial byte/SQL outcomes with caller/unknown-commit limits. Preserve all91 prior test classes and cases/assertions; run meaningful boundary/compatibility/focused/full isolated gates, syntax/scoped style, unchanged100-route/32-table safe CLI and complete source/reference/docs checks.

Dependencies: P4-044 reviewed design and P4-043 full 2,166 tests/43,324 assertions. The starting baseline contains 416 non-document paths; all 413 outside the authorized controller/renderer/import-result Blade delta are preserved, alongside all 39 original PNGs/five UI references and all 20 UX/eight batch/39 NOT STARTED rows. No real data/account/owner/service/environment/build/dependency/deployment operation, additional schedule/worker or premature customer route occurs. Family 1 source acceptance is recorded below; Phase4, families 3/4 and actual rollout prerequisites remain open.

### IMPLEMENTED - P4-045 verification and limits (2026-11-04)

P4-045 is COMPLETE in source/tests (2026-11-04) for the existing Guest creation/import, QR generation and image/manual-code repair family. LegacyGuestIssuance freezes canonical context and uses one local mutation attempt with current primary authority, separate rotation/missing-only/repair modes, write-free rendering and guarded strict file effects. Import continuation is limited to a known rolled-back inner failure; authority or uncertain outcomes interrupt privately. Full isolated gate: **2,288 tests / 44,727 assertions**. Family 1 source is accepted; families 3/4, Phase4, actual rollout and all 39 approved UI implementations retain their open gates. [Implemented contract and limits](LEGACY_GUEST_ISSUANCE.md).

Original-source HTTP observation: **63 tests / 270 assertions / 49 failures / zero errors (14 passing controls)**; 39 direct future-service cases are excluded from that comparison. Failures include newly required boundary/context observations and are not a count of distinct vulnerabilities. Boundary **122 tests / 1,403 assertions**, existing Guest/import/QR/scanner/offline compatibility **246 tests / 2,825 assertions**, focused **465 tests / 7,081 assertions** and full **2,288 tests / 44,727 assertions** (5279.84797 seconds JUnit) pass with zero errors/failures/skips. All **91 prior classes** retain exact case/assertion counts and case identities. Fixtures guard actual SQLite :memory:/PDO/autocommit and unique TEMP fake files; no configured database or existing application/customer storage bytes are changed. The recorded JUnit duration includes host suspension observed during the run and is not an application performance benchmark.

The source scope is one new service/two fixed exceptions/two narrow catalogs/one independent regression class, plus the authorized controller, renderer and import-result Blade changes. The narrow Blade scope resolves the reviewed honest-interruption contract; it is not an approved-reference visual implementation. AdminController has 91 methods before and 87 after: 10 existing bodies change, 7 obsolete helpers are removed and 3 helpers are added; its 74 other methods and remaining bytes outside the named methods/import delta are exact. GuestQrImageService changes only manualCodeFor, retaining its other 8 methods and outside bytes. All 371 frozen tested-source paths and 413 unrelated non-document baseline paths match, including environment/app key, Git index and builds. The unrelated count falls from 414 to 413 because the existing import Blade joins the explicit source delta; the frozen tested-source inventory remains 371. Syntax/scoped read-only Pint and unchanged 100-route/32-table safe CLI pass. All 39 original PNGs, five protected UI documents, 20 UX rows, eight reference batches and 39 NOT STARTED screen statuses remain exact. No route/schema/admission/provider/identity, real data/account/owner/service, environment/dependency/build, private-media migration or deployment change occurs. Phase4 remains IN PROGRESS.

Current primary authority, bound/consumed preview context and one-attempt issuance now cover the entire named family. Explicit rotation, missing-only no-op and existing repair remain distinct; valid offline image access is a separate read observation, followed by fresh mutation authority only when needed. Actual PDO/rollback/commit or unknown outcomes cannot become continuable row failures; private partial results require renewed access. File references/existence do not prove provenance or atomic storage behavior. Families 3/4 remain OPEN; no automatic callback/receipt microtask or new phase is opened. [Contract](LEGACY_GUEST_ISSUANCE.md).

## Task P4-046 - COMPLETE (started 2026-11-04; completed 2026-11-05)

P4-046 is COMPLETE as read-only source/design documentation (2026-11-05). The reviewed whole-family contract covers current read projections, import preview/result presentation, buffered CSV/XLSX, shared layout and private QR PNG/ZIP delivery, with one coupled storage policy for readers, issuance and deletion. No runtime guard or storage cutover is installed. P4-045 remains the implemented **2,288 tests / 44,727 assertions** checkpoint; 371 tested-source and all 432 non-document baseline paths are preserved without a backend rerun. Families 3/4, Phase4 and actual rollout remain open. [Reviewed design](LEGACY_READ_EXPORT_MEDIA_DESIGN.md).

Independent read/export and media/storage reviews approved the exact staged design after the import-result binding, actual package lineage, historical creator-label and shared all-byte storage corrections. UTF-8, local links, task statuses and source/reference preservation pass. All 39 original PNGs, five protected UI documents, 20 UX rows, eight reference batches and 39 NOT STARTED screen statuses remain exact. The retained CLI evidence is 100 route method/path pairs and 32 inventory tables; no new CLI/backend or actual-environment result is claimed.

The finite endpoint/helper matrix includes import duplicate previews and actor/Event-bound saved results, exact Event/Guest history attribution, safe current Guest/QR/User decoration, null-Event notification exclusion and scalar layout Session/package/creator data. CSV/XLSX serialization and PNG/ZIP reads complete before response success; later callbacks emit frozen bytes only. Read preparation does not promise continuous transfer authorization.

The selected guest_qr private local disk has shared configured/actual-root and exact-key checks across probes, issuance, repair, deletion and delivery, with global reference vetoes and no public fallback. Fresh delivery follows any separate repair; whole-archive refusal does not undo earlier repairs. The fenced inventory/copy/checksum/collision/static-denial/cache/restore protocol retains real-operation gates and explicit file/SQL partial outcomes.

P4-047 is the next bounded implementation, with reviewed QR-only fixture adaptations, independent regression coverage, meaningful affected/full gates and selected synthetic engine observations. No application/test/schema/route/configuration/environment/build/dependency/service, real data/account/owner/deployment or scheduling change occurs in P4-046. Family 3 source, scanner authority, Phase4, customer/Phase5/UI work and actual rollout remain open.

## Task P4-047 - COMPLETE (SOURCE LEVEL) (started 2026-11-05; closed 2026-11-11)

Objective: implement the complete existing administrator read/export/ordinary-QR family and its named shared layout/import/storage dependencies against the [independently reviewed P4-046 design](LEGACY_READ_EXPORT_MEDIA_DESIGN.md).

Starting gate: P4-046 design/document verification COMPLETE; P4-045 source 2,288 tests / 44,727 assertions, 92 classes, 100 routes and 32 inventory tables. The immutable task baseline captures 533 paths including 432 non-document paths and 371 tested-source paths. At task start, work prepared isolated original-source observations in TEMP before candidate installation; the dated checkpoints below are historical and the final closure supersedes their pending-work statements.

Scope: narrow current read projections and buffered QR delivery services plus one shared private local storage policy; named AdminController read/export/media/import-presentation helpers; scalar view/layout composer dependencies; CSV/XLSX preparation; private guest_qr disk and canonical-prefix static denial; coupled issuance/deletion storage/probe checks; bound import-result context/invalidation; narrow SW/EN failures; independent regression coverage and reviewed ordinary-QR fixture adaptations. Existing routes, schema, credentials, admission algorithm, offline writers and unrelated public assets remain governed by their existing contracts.

Acceptance: original-source HTTP/render/stream/media observations before candidate installation; complete current actor/Event/root/related projection and relation-filter guards; historical scalar retention and explicit null-Event log exclusion; no private SQL or resource retargeting after preparation; complete buffered CSV/XLSX/PNG/ZIP with compatible formats/names/offline repair and sanitized failures; exact-key/root/shared-reference checks before all ordinary QR byte effects; no public fallback; explicit file/caller/commit/cache limits. Preserve all 92 prior classes/cases and semantic assertions, document any required fixture delta, and run meaningful boundary/compatibility/focused/full tests, selected synthetic MySQL root/caller visibility observations, syntax/scoped style and source/reference/document checks.

No configured data/account/ownership change, real file migration/retirement, environment/dependency/build/service/deployment or new scheduling action is authorized by this source task. Actual migration/copy readiness, static alias/cache denial, DB-plus-file/key restore and intended-host/device proof retain rollout gates. Family 3 source, scanner family 4, Phase4, customer/Phase5 and approved-reference UI work remain open.

### IN PROGRESS - P4-047 slice 1 verification and limits (2026-11-07)

Installed in source (no new route, schema, disk, service class, dependency or configuration):

| File | Change |
|---|---|
| `app/Http/Controllers/AdminController.php` | Check-in list, check-in CSV, invalid-scan CSV and dashboard gate activity decorate history only with a current Guest of the selected Event and a scanner of the same Event (exact scanner role bytes, not platform, no workspace membership; inactive kept as a label), mirroring `LegacyOfflineScannerReview`. Check-in name/phone search stays inside selected-Event Guests. Guest-list history counts and Guest-detail history require both Guest and Event. Notification logs show the exact selected Event only. Import results carry `guest_import.result_context` (actor/Event) and are shown only on an exact match; unbound or mismatched results are discarded. CSV rows are serialized before the response; the callback emits frozen bytes. |
| `app/Services/ExcelExportService.php` | QR-list and import-template workbooks are serialized before the response and disconnected in `finally`; the callback emits frozen bytes. |
| `app/Providers/AppServiceProvider.php` | Admin-layout banner: creator label only for the current actor or an eligible legacy administrator (exact admin role bytes, not platform, no workspace membership; inactive kept); package time only when the package links back to the same session. `OfflineEventLockService::getActiveSessionForEvent` is unchanged for its other callers. |
| `tests/Feature/LegacyReadExportBoundaryTest.php` | New 18-case boundary class with guarded memory-SQLite/TEMP fixtures and before/after table and file snapshots around every read. |

Deliberate visible changes: null-Event notification rows leave the selected-Event log page; history rows whose current Guest or scanner moved to another Event show "Unknown QR", blank or "Unknown" while keeping their own stored time/result/gate/counters/IP; check-in search no longer matches other Events' Guests; Guest history counts exclude other-Event rows; banner creator/package labels fall back to "Unknown user"/"Not recorded". CSV/XLSX headings, order, types, filenames and formula protection are unchanged.

Evidence: the 12-case TEMP observation class failed its 9 gap cases and passed its 3 controls on the retained source before any change. The final boundary class was also run against hash-verified originals swapped in and restored: 15 cases fail (including the predicted XLSX second-emission error) and 3 controls pass. On the candidate: boundary 18 tests / 203 assertions; full suite on the controller-only first change set 2,288 / 44,727; full suite on final bytes 2,306 tests / 44,930 assertions with source hashes unchanged during the run. Pint `--test`: changed files add no new fixers (`AdminController.php` keeps the same 8 pre-existing fixers). TEMP evidence: `codex-harusi-p4047-20261105/slice1-evidence-20261107.md`.

Open decision: `ScannerController::scannerEvent()` lets a scanner with no assigned Event (null `event_id`) admit for the session-selected or default Event. Under the reviewed "captured Event" operator rule, such rows now show "Unknown" in administrator history. Widening the rule to unassigned, non-platform, non-member scanners would need a design decision; no existing test covers that case.

Limits and remaining P4-047 work: no one-attempt primary/locking read bundle or prepared layout yet, so layout and pages still query during rendering; canonical raw-ID postchecks are not added; the HEX role-byte guard targets MySQL collations and is not observable on SQLite; no MySQL observation has run. Private guest_qr storage, static denial, coupled issuance/deletion storage checks, buffered PNG/ZIP delivery, import duplicate-preview projection, SW/EN failure labels, the nine QR fixture adaptations and independent candidate review remain before family 3 source acceptance.

### IN PROGRESS - P4-047 slice 2 verification and limits (2026-11-08)

Slice 1 independent review (2026-11-07): a separate read-only reviewer returned APPROVE WITH FIXES with no code defect in the claimed behavior. Its test gaps were closed with 8 cases (operator classification for platform/member/role-changed/inactive scanners; another-actor, padded, missing-actor, absent and non-array import-result contexts; platform- and workspace-linked self-creator banner cases) plus XLSX snapshots around the callback emissions. Temporary mutations, each restored and hash-verified, made the operator case fail (1/1), three of the five context cases fail when the actor comparison was removed (the absent and non-array contexts are refused by the separate shape check) and both self-creator cases fail (2/2). Its omissions were fixed in source: CSV/XLSX responses are private no-store/nosniff and buffer in `php://memory` rather than `php://temp`; successful import results are published only after the same current actor/Event check used on interruption; withheld results show generic localized re-preview guidance (`guest_issuance.result_withheld`); unused eager loads were removed. Private no-store for ordinary HTML pages remains open.

Installed in source (no route, schema, dependency or real-file operation): `App\Services\GuestQrStorage` is the one shared policy for the fixed private `guest_qr` disk (`storage/app/guest-qr-private`; local, private, throw true, report false, no url/serve/prefix/link). It validates configuration, an existing real absolute root outside every web-public/storage-link/served-disk root (a symlink or junction alias is refused), the resolved local adapter and the root its I/O actually uses (the stock Flysystem operator, with its stock path normalizer, must wrap exactly the stock local adapter, whose private prefix is read by reflection and must equal the validated root; a stale cached handle, a handle borrowed from another disk or a key-rewriting normalizer is refused), and exact relative keys (no absolute/drive/UNC/URL forms, control bytes, backslashes, colons, empty/dot/dot-dot segments, trailing dot/space, Windows device names including COM¹–³/LPT¹–³, CONIN$/CONOUT$ and a device name before a spaced extension, or 8.3 short-name forms such as `qr-cod~1`; historical keys outside qr-codes remain permitted). Generated keys for an Event whose slug equals a device name ("Con", "LPT1") use a `_event` suffix on that segment. `LegacyGuestIssuance` and `LegacyGuestDeletion` resolve bytes only through it after their existing authority and reference checks; every truthy key is validated first, and the compatible-image probe now applies the global shared-path veto before reading bytes, so unsafe or shared keys are fixed failures, never a repair signal. `App\Services\Tenancy\LegacyQrMediaDelivery` runs the existing guarded repair per member, then one read-only transaction attempt that locks the current unowned Event, exact active administrator and the complete frozen Event-scoped Guest/QR set, re-validates every key and global path reference, and reads every byte (null/empty/thrown reads refuse) before success. All-ZIP membership is frozen by a primary entry read before repairs; selected ZIPs refuse rather than shrink. The four endpoints send private no-store, nosniff, quoted inline/attachment dispositions and full bodies only; ZIP32 bounds refuse before packing, keeping count/size/offset fields below the ZIP64 sentinels; failures found by the repair probe keep the existing issuance feedback, and failures found only by the delivery recheck use the fixed localized `guest_qr_media.failure` (SW/EN). `public/.htaccess` denies `storage/qr-codes` before the existing-file bypass. There is no public fallback.

Evidence: the TEMP pre-change observation (15 cases incl. 3 alias data sets) failed all 11 gaps for the designed reasons and passed all 4 controls on the unchanged media code; the same matrix passes on the candidate (15 / 78). The new `LegacyQrMediaBoundaryTest` passes 61 tests / 424 assertions: the 15 observed cases; 16 policy/coupling cases added before the review (nine unsafe disk configurations, stale handle, empty delivery read with EN/SW feedback, private-only deletion, unsafe-key deletion and explicit-generation refusals, empty archive); and 30 review follow-up cases (listed below). No original-source run is claimed for the 46 later cases; their discrimination evidence is the mutation runs. The nine QR-coupled classes were adapted mechanically by recorded scripts that retarget only ordinary-QR disk operations, keep unrelated public sentinels and convert the two inspecting spies into trusted local observation wrappers built from the guarded disk's own driver/adapter/config; together they pass 322 tests / 3,866 assertions, LegacyGuestIssuanceTest alone 122 / 1,403. One candidate correction preserved existing behavior: all-ZIP membership is frozen by an entry read rather than a transaction, keeping the existing moved/missing 404 cases. On the final bytes, the isolated full suite passes 2,375 tests / 45,441 assertions in 94 classes. A per-case comparison with the retained P4-045 gate finds all 2,288 retained cases with identical assertion counts and new cases only in the two boundary classes, the nine adapted classes still total 322 / 3,866, and the 27 changed or added files were hash-verified after the run. (The pre-review candidate passed 2,345 / 45,175. An intermediate run that the host stalled for over two hours failed one unchanged, unfrozen-time CustomerIdentityTest reset-token case at the 60-minute expiry; that test file matches its baseline hash and passed 41 / 439 when re-run alone, and the final run above passed it.) Pint `--test` reports only pre-existing fixers (AdminController keeps exactly the original 8, confirmed on the byte-exact original; AdmissionScannerTest's array_indentation, confirmed by baseline-hash reconstruction).

Slice 2 independent review (2026-11-08): a separate read-only reviewer returned APPROVE WITH FIXES, with no blocker and no policy bypass under the shipped configuration. Fixes:
- Delivery-stage refusals had no triggering test. 15 cases now change state as the locked delivery transaction begins, after every repair: a foreign-Event shared path, a moved member, a deleted QR, an unsafe key and a demoted actor, each for single, selected and all-ZIP delivery. Further cases cover a divergent public copy, null/throwing delivery reads, exact-key accept/refuse lists and ZIP32 count/name limits. The read-fault spies are registered rather than mocked, so a public fallback would show.
- The actual I/O root is now verified (operator, normalizer, adapter and prefix, as above), with refusal cases for borrowed public handles, a root-ignoring local creator and a rewriting normalizer.
- Device-name Event slugs get an `_event` suffix, and the extended device/8.3 key forms are refused.
- The probe-versus-delivery message mapping, guest deletion in the fail-closed lists and the coupled issuance/deletion contracts were corrected.
- Symlinked release layouts and refused legacy keys are now deployment notes, and ZIP32 fields stay below the ZIP64 sentinels.
- The two spy classes fake the public disk again and include it in every snapshot (recorded script `adapt_spy_public_observation.py`; no assertion added; reversing it and the earlier script reproduces both originals byte-exact).

The same reviewer then approved all six fix areas; its one further note (a stock operator with a key-rewriting normalizer) is closed by the normalizer check. Fourteen temporary mutations, each restored and hash-verified, made their targeted cases fail: removing the delivery path veto, per-row key check, actor recheck or Event scoping (3 cases each), either introspection check, the normalizer check, the ZIP sentinel bound, the device-name suffix or the extended key rules; adding a public fallback after a failed read (6/6); and letting deletion continue without the root. The public-copy issuance mutation was caught by the facade spies and the media guard, not by the new public snapshot entries. Two single-layer removals (frozen-set count, target count) stay refused by later fail-closed layers; this was established by inspection, not run. The isolated route list still shows 100 routes; no storage-serving route is added for the new disk (the existing `storage/{path}` routes serve only the `local` disk at `storage/app/private`).

Deployment consequence (release gate, not done): with this source, ordinary QR images are read and written only in the private root. Until an operator follows the gated cutover in the design (create the private root, fence writers, inventory and copy existing public QR files with checksums, deploy the static denial or host equivalents, verify old URLs are refused, then reopen), QR preview/download/ZIP, QR generation/repair, single-Guest creation (which always issues a QR in the same attempt), imports with QR generation and deletion of Guests that have a QR path fail closed with the existing fixed feedback and roll back; existing public copies are neither served nor deleted. This source task performs no copy, migration or deletion of real files. [Deployment item 10](DEPLOYMENT_CPANEL.md).

Limits and remaining P4-047 work: slice 2 is reviewed and verified but P4-047 is not accepted until its remaining contract passes; one-attempt primary/locking read bundles with prepared layout (zero render-time private SQL) and private no-store HTML; import duplicate-preview projection; canonical raw-ID postchecks; selected synthetic MySQL root/caller observations. The actual I/O root is read from Flysystem 3 internals by reflection; a dependency change that alters them fails closed until reviewed. Subclassed Illuminate local wrappers are trusted code (the tests' observation wrappers rely on this), so a wrapper that overrides its byte methods is outside the policy. Any symlink or junction in the root path refuses, so symlinked release layouts must point Laravel's storage path at the real directory. Stored keys the policy refuses (including device-name segments created on Linux before this change) stay refused for media, regeneration and deletion until the cutover inventory fixes them. Alias/TOCTOU protection against privileged external writers, host static/alias/CDN denial, cache invalidation and DB-plus-file/key restore remain actual-host gates. The unassigned-scanner open decision above is unchanged.

### IN PROGRESS - P4-047 slice 3 verification and limits (2026-11-08)

Installed in source (no schema, route path, dependency or real-file change):
- `App\Services\Tenancy\LegacyAdminReadProjection::layout()` prepares the shared admin layout in one read-only transaction attempt.
  - It first takes primary shared-lock reads, in order: the exact unowned selected Event, then the exact active administrator, then that Event's blocking offline Session.
  - The selection is optional. An unsafe, missing or workspace-owned selection projects nothing instead of refusing the page, and archived or cancelled unowned Events stay selectable.
  - The actor check is raw role admin and active, so platform- and workspace-linked legacy administrators stay compatible. A demoted, inactive or missing actor is refused with 403.
  - Three reads are unlocked primary reads:
    - the package fallback time (the package must link back to that Session), which needs no lock of its own because package writers already take the Event and Session locks held here;
    - the creator label (the current actor, or an exact legacy administrator), because locking other users' rows could invert those users' own Event → actor lock order;
    - the active-unowned switcher catalogue, for the same reason across other Events.
  - Non-SQL failures become the fixed `LegacyAdminReadUnavailable`; actual SQL errors keep framework handling.
- A view creator registered for all 22 views that extend `layouts.admin` (`LegacyAdminReadProjection::LAYOUT_PAGES`) prepares these values when the page view is made, before any rendering, unless the page already supplied them. The `layouts.admin` composer only consumes the frozen values: it issues no SQL and rereads no session state. Views receive immutable `App\Support\AdminLayout` values with no model or lazy SQL. Without prepared values an authenticated render refuses with `LegacyAdminReadUnavailable`, and an unauthenticated one renders the empty layout. The old composer banner query in `AppServiceProvider` is removed.
- `PrivateNoStoreResponse` (alias `admin.private`) adds `Cache-Control: private, no-store, max-age=0` and `X-Content-Type-Options: nosniff` to the 11 family-3 HTML routes: dashboard, Guest list/detail/edit/create, import GET and preview POST, QR list, check-ins, notification logs and reports. Other admin pages keep their existing cache headers. Export and media responses already had the policy.

Evidence: the TEMP observation `LegacyAdminLayoutBaselineTest` (34 cases) ran on the unchanged source. All 31 gaps failed for the designed reasons:
- on all 19 observed pages, the composer's five queries ran while the layout rendered;
- a render-time session change retargeted the layout on a family-3 page and on a catalogue page;
- the 10 family-3 GET pages sent `no-cache, private`.

Its 3 controls passed after a fixture correction (the migrations' default Event 1 and random factory dates). The same 34 cases pass on the candidate (34 / 121). The repository `LegacyAdminLayoutBoundaryTest` passes 54 tests / 208 assertions: the 34 observed cases plus 20 added after the change:
- all layout views registered (source scan);
- one locked Event → actor → Session attempt with unlocked package/creator/catalogue reads (grammar intent);
- actor refresh: demoted, inactive and missing actors refuse, platform- and workspace-linked actors stay compatible (5);
- unusable selections project nothing (8), and an archived selection stays selected outside the catalogue;
- the composer issues no SQL without prepared values;
- layout preparation writes nothing;
- family-3 nosniff and the import POST headers;
- non-family-3 pages keep their cache policy.

Seven temporary mutations, each restored and hash-verified, failed their targeted cases: removing the actor refresh (4), the Event lock, the owned-Event exclusion or `admin.private` on reports (2); a querying composer fallback; an unregistered layout view, which only the source scan catches because that page then renders an empty layout without SQL; and locking the creator row. The isolated full suite passes 2,429 tests / 45,650 assertions in 95 classes. A per-case comparison keeps all 2,288 retained cases exact apart from one documented delta (below), new cases appear only in the three boundary classes, the nine adapted classes stay 322 / 3,866, and the 33 changed paths were hash-verified after the run. Pint passes on new and changed files; bootstrap/app.php keeps its two pre-existing fixers, confirmed on a reconstruction whose hash equals the baseline. The isolated route list shows 100 routes with unchanged paths, and exactly the 11 intended routes carry `admin.private`.

Slice 3 independent review (2026-11-08): a separate read-only reviewer returned APPROVE WITH FIXES, with no blocker and no deadlock cycle found in the Event → actor → Session order. Fixes applied on 2026-11-09:
- An unregistered layout view failed silently, so an authenticated render without prepared values now refuses, and the source scan recognises `@extends` spacing, slash names, `@extendsFirst` and intermediate views.
- Blade received live models, so the layout now receives immutable values only.
- Every admin page held exclusive locks on the selected Event and actor, which would make page views block each other, so these reads now use shared locks. Lock waits still need the MySQL observation.
- The lock rationale and the wording below were corrected. Middleware responses on the 11 routes (the "select an event" redirect, a missing `{guest}` 404 and authentication responses) run before `admin.private` and keep `no-cache, private`; they contain no private data.

After the fixes: boundary 56 / 244, the 34 observed cases still 34 / 121 and full 2,431 tests / 45,686 assertions (95 classes), with a per-case comparison again keeping all 2,288 retained cases exact apart from the one delta below. Eight mutations of the fixed code failed their targeted cases, using a TEMP autoloader overlay that writes nothing to the repository: removing the actor refresh (4), the Event lock or the composer refusal; accepting an owned Event; a querying composer fallback; an unregistered view (2); locking the creator row; and an exclusive Event lock. The earlier route mutation (`admin.private` on reports) is not repeated because `routes/web.php` is byte-identical. The previous harness was killed mid-run and left its first mutation in the repository; the original was rebuilt, matched its recorded hash and restored. Two intermediate full runs were each disturbed by host standby (7 h 21 min and 1 h 26 min) and failed only time-dependent cases, the CustomerIdentityTest reset token and a RecoveryScriptSafetyTest subprocess timeout, both of which pass alone (41 / 439 and 33 / 698). The fixes have not been re-reviewed separately; the final independent review covers them.

Documented retained-count delta: `LegacyEventAdministrationBoundaryTest::test_catalogue_statistics_and_switcher_exclude_ids_outside_the_supported_positive_integer_range` asserts "no write SQL" once per executed query. The standalone layout's designed actor refresh adds one primary read, so the case now runs 30 assertions instead of 29. Its file is byte-identical to the baseline and every assertion still holds.

Limits and remaining P4-047 work:
- Family-3 pages still prepare the layout in its own attempt rather than inside the page's read bundle.
- Page content still uses ordinary reads and can lazy-load while rendering.
- Still to do at that point (slice 4 below closes all but the final review):
  - locking materialization with identity-set pagination and PHP arithmetic;
  - raw-ID postchecks on page roots;
  - the import duplicate preview;
  - selected synthetic MySQL observations, including the layout's lock order and lock waits;
  - the final independent review.
- The no-store policy cannot recall copies cached before it applied.

### IN PROGRESS - P4-047 slice 4 verification and limits (2026-11-09)

Installed in source (no schema, route, dependency or real-file change; 100 routes, 11 with `admin.private`):
- `LegacyAdminReadProjection` now reads each family-3 page, export and import preview in one read-only attempt, in this order:
  1. the required exact unowned selected Event;
  2. the exact active administrator;
  3. the blocking offline Session;
  4. the page's private rows, share-locked in primary-key order before any display-ordered read;
  5. the layout's unlocked labels.
- A page's layout values come from that same attempt, so the view creator prepares nothing further and there is one transaction per request.
- Statistics are computed in PHP from materialized rows. Status and pass classifications stay SQL `CASE` expressions on those rows, so the database collation decides them exactly as it did for the former aggregates.
- Page totals and windows come from one identity set, with the former page sizes and behaviour for last, out-of-range and invalid pages. QR filters use the materialized QR state. Check-in name and phone search first materializes matching Guests of the selected Event.
- Views receive immutable `App\Support\AdminRead` values with no model and no lazy SQL. The full QR credential no longer reaches any view, only the display tail the QR list already showed.
- The Guest detail and edit pages read the current row, so a moved Guest refuses with 404 even if the route binding holds an older copy. A row that disappears under the locks refuses the whole result.
- CSV and XLSX bytes are complete before their attempt ends, and QR-list file names come from the captured Event and Guest scalars.
- The import preview classifies duplicates inside the attempt with the existing phone OR `LOWER(TRIM(name))` match and first-PK tie-break, and stores no preview when refused. `create` keeps the standalone layout because it reads no private row.
- Principal Event-scoped locking reads name their indexes (`guests_event_id_index`, `checkins_event_id_index`, `notification_logs_event_id_channel_status_index`, `qr_codes_guest_id_unique`). Some check-in locking reads remain unhinted (N4); not every locking read names an index.
- `LegacyQrMediaDelivery` now uses shared locks and named indexes, and its global path check is an unlocked primary read. Delivery writes nothing, and by inspection the former locks could have stopped scanner admission on every Event for as long as an archive took to read.

Evidence: the TEMP observation (82 cases) ran on the unchanged page source. Its 60 gaps failed for the designed reasons, including private reads outside any attempt, SQL aggregates, models in views, a mid-read selection change retargeting the content or layout of all six observed pages, and a stale bound Guest served after moving. Its 22 controls passed: zero render-time SQL (9), no writes (9), the two detail pages with no aggregate, the import page with no private read and the reports page with no model. The same cases pass on the candidate. The repository `LegacyAdminReadBundleBoundaryTest` passes 135 tests / 641 assertions, and `LegacyQrMediaBoundaryTest` gains one lock-intent case (62 / 426).

Forty-five mutations failed their targeted cases, using a TEMP autoloader overlay that writes nothing to the repository. A first pass exposed two weak cases, which were strengthened: a QR with only one revocation signal, and a selected-Event attempt whose Guest moved. A planned mutation dropping `useWritePdo` from a locked read was removed as equivalent, because Laravel's `lock()` already selects the primary connection.

The first full run caught two regressions, a removed type-hint import and a changed `import()` signature, and both were corrected without touching a retained test. The isolated full suite then passes 2,567 tests / 46,329 assertions in 96 classes. A per-case comparison keeps all 2,288 retained cases exact apart from the one documented delta, new cases appear only in the four boundary classes, and the nine adapted classes stay 322 / 3,866. No schema file changed. Pint passes on new and changed files, and AdminController keeps 7 of its 8 pre-existing fixers with none added.

[Selected MySQL and MariaDB observations](MYSQL_READ_BUNDLE_REHEARSAL.md) pass 45 cases on each engine. They include current rows under a caller's older snapshot, refused revocation, lock waits, both admission orders with no deadlock and aggregate conservation on the real collation. Their first run led to the named indexes, the QR read by Guest ID and the QR delivery lock change above.

Visible changes:
- A family-3 attempt that loses its selected Event or actor refuses as described, with no private rows read.
- Check-in search no longer matches a selected-Event attempt through a Guest who moved to another Event.
- Canonical-ID guards reject unsafe loaded roots. Extreme unsigned-ID casts and negative history-summary IDs remain the N2 follow-up; universal exclusion of every unsafe ID from totals is not claimed.

Filters, ordering, page sizes, headings, columns and file names are otherwise unchanged.

Limits and remaining P4-047 work:
- The final independent candidate review remains. The slice 3 review fixes and this slice have not been independently reviewed.
- While an attempt runs, writers to that Event's rows wait. The largest measured page held about 50,000 locks for about 1.2 seconds with 5,002 Guests and 20,001 history rows. No sustained load or real host was measured.
- Unlocked labels and the QR path check can be stale inside a caller's older repeatable-read snapshot.
- The named indexes must exist on the target database. A missing index fails family-3 pages with a SQL error. [Deployment item 11](DEPLOYMENT_CPANEL.md) checks them.
- The earlier open decision on unassigned scanners and every rollout gate are unchanged, including the private QR cutover.

### Historical P4-047 final review findings (2026-11-09)

The final independent review returned BLOCKED on two frozen-context findings: F1 (the import page reread the session after its read attempt) and F2 (list pages read the page number during their attempt). F1 and F2 corrections were implemented; the final combined verification below now closes both findings.

### P4-047 final verification and source closure (2026-11-11)

Decision: **APPROVED WITH NONBLOCKING FOLLOW-UPS**. P4-047 is **COMPLETE (SOURCE LEVEL)**; family 3 source acceptance is closed against its existing P4-046 contract. This closure changes documentation only.

- F1 PASS: `AdminController::import()` captures the actor and canonical Event before `importPage()`. The saved-result check uses that captured context, clears mismatched result/context and still renders correctly bound results.
- F2 PASS: Guest, QR, check-in and notification lists capture page/path/query before their read attempt. Rows, page metadata, links and Guest history counts use the same captured window; Event authorization is unchanged.
- Focused isolated SQLite boundary gate: **291 tests / 1,801 assertions**, zero errors/failures/skips (JUnit aggregate 277.678s): read bundle 147 / 784, read/export 26 / 290, layout 56 / 244 and selected Event 62 / 483.
- Complete isolated suite run **once** against the final source: **2,579 tests / 46,472 assertions**, 96 classes, zero errors/failures/skips (JUnit aggregate 560.965s). All 2,567 slice-4 cases retain exact per-case assertion counts; the 12 new cases contribute 143 assertions. Existing F1/F2 red/green, 253 retained-case and five caught pagination-mutation evidence is retained without another mutation run.
- One bounded fresh-context, read-only independent **AI** review (not human review): APPROVED WITH NONBLOCKING FOLLOW-UPS, no acceptance-blocking defect found. Its scope was F1/F2, new tests, relevant P4-046 clauses and preservation of accepted behavior; no wider audit or review cycle.
- All 382 captured source/config/test/environment/index paths remain byte-identical. No application, test, schema, authentication, Android, dependency or QR-file change was made. Tests used memory SQLite, TEMP storage and null/array integrations; no production/staging database or real QR file operation occurred. Earlier selected MySQL/MariaDB observations are retained, not rerun.

N1-N5 remain recorded in the [P4-047 follow-up register](PHASE_04_REPORT.md#p4-047-follow-up-register), linked to existing R01/R06/R18 risk scopes. The image preflight lock concern is documented, not fully resolved; page-only measurements are not an upper bound for QR/import/export latency. No follow-up implementation or new investigation was started.

Phase4 remains **IN PROGRESS** (scanner family 4 remains open). Real database verification, legitimate ownership/backfill, private QR/media migration and old-copy denial, DB/file/key restore, deployment/host/device checks and production readiness remain external gates. Production readiness is **NOT VERIFIED**. `Harusi-Codex-Continue-4h` remains Disabled. Next task ID: **P4-048**; no task started and no automatic continuation.

TEMP evidence: `p4047-final-verification-20261111/focused.xml`, `full.xml`, their logs, `final-gate-summary.json`, source/document fingerprints and `independent-ai-review.md`.

## Task P4-048 - IN PROGRESS, Step 1 (2026-11-11)

Approved scope: P4-048 previously reserved an ID only. This bounded step selects **"allowed current Event state"** from [Phase 4 exit register, family 4: Scanner authority, admission and read/log effects](PHASE_04_EXIT_AUDIT.md#4-scanner-authority-admission-and-readlog-effects), consistent with the inactive/cancelled-event verification gate in [Security model](SECURITY_MODEL.md#planned--verification-gates). The existing [scanner authority and browser response contract](SCANNER_ARCHITECTURE.md#current-browser-endpoint-contracts) remains the compatibility baseline. One operation: `POST /scanner/validate`, `ScannerController::validateQr`.

Observed issue and correction: an active scanner could validate a valid pass for an archived/cancelled Event, receive Guest details and append Checkin/ScanLog rows. The controller now re-reads only the resolved Event's status using the primary connection and requires exact `Event::STATUS_ACTIVE` before input processing, QR lookup or scan writes. It returns the existing framework 403 authorization response otherwise. It reuses the existing Event resolver, identity/role middleware and offline lock service; no new admission or authorization service is introduced. The fresh status read also refuses a cancellation after the offline middleware cached an active Event. This is an entry-time check, not a locked authorization guarantee through logging or response delivery.

Verified with synthetic memory-SQLite data and isolated TEMP storage/cache settings:

- Before the controller correction, the expanded validation class ran 20 cases: all 5 event-status cases failed with actual 200 versus expected 403; the 15 controls passed. After the correction: **20 tests / 84 assertions**, no failures/errors/skips.
- The 13 added cases cover assigned archived/cancelled Events, a stale cached Event, unauthenticated access, persisted account deactivation, scanner role revocation to admin/customer, persisted reassignment despite a stale authenticated model, rejection of foreign session/client Event overrides, and explicit null-assignment selected/default fallback for active and inactive Events. Denied authority cases compare Guest/QR/Admission/Checkin/ScanLog snapshots; archived/cancelled cases preserve pre-existing validation history. Wrong-event business rejection retains its anonymous zero-entry Checkin/ScanLog without changing Guest/QR/Admission data.
- Focused regression command: `php vendor/phpunit/phpunit/phpunit --filter 'ScannerQrValidationTest|ScannerConfirmAdmissionTest|ScannerManualSearchTest|ScannerInterfaceTest|AdmissionScannerTest::test_scanner_allows_partial_use_and_blocks_over_limit|AdmissionScannerTest::test_revoked_qr_code_cannot_be_admitted|MultiEventSupportTest::test_scanner_cannot_validate_or_admit_qr_from_another_event|OfflineEventLockTest::test_active_offline_session_blocks_(laravel_qr_and_manual_admissions|scanner_validation_routes_without_logs)' --no-coverage --do-not-cache-result`. Result: **57 tests / 414 assertions**, no failures/errors/skips. This includes valid/revoked/cancelled/fully-used passes, partial and bounded QR/manual admission, browser pages and HTTPS, cross-event denial and offline 423/no-log behavior. The full application suite was not run.
- PHP syntax checks and Pint `--test` pass for both changed PHP files. Initial test-runner cache-path setup errors were corrected before the before/after observation; an initial regression run's localhost HTTPS-fixture failure was resolved by the synthetic `http://pangaevents.test` test URL, without application/test changes for that failure.

Files changed for this step only: `app/Http/Controllers/ScannerController.php`, `tests/Feature/ScannerQrValidationTest.php`, `docs/PROJECT_PLAN.md`. Existing worktree changes were retained. Evidence: `%TEMP%/p4048-step1-20261111-a544d48f41ed45b7a430a26b452293d7/{before,after,regression}.xml`.

Preservation and limits: routes, payloads for existing allowed-event outcomes, QR tokens, guest counters, admission logic and offline protocol are unchanged. The documented unassigned-scanner selected/default fallback remains in place; removing it still needs an explicit authorization decision. This step does not close the family's canonical persisted assignment, global QR lookup/lineage, write-time revocation, or all-path/middleware authority requirements. No MySQL concurrency, host, device, production rollout or real-data check was performed. P4-047 and N1-N5 are not reopened; private QR migration, Phase5, UI and Android work are not started. **P4-048 IN PROGRESS; Phase4 IN PROGRESS; production readiness NOT VERIFIED.** `Harusi-Codex-Continue-4h` was read-only checked as **Disabled** and left unchanged.

At the Step 1 handoff, the next small action was the active-Event authorization boundary on `POST /scanner/verify`, and work stopped as requested. The subsequent user continuation requests resume that action below.

## Task P4-048 - IN PROGRESS, Step 2 (2026-11-11)

Scope: continue the recorded next action with **`POST /scanner/verify`, `ScannerController::verify` only**. Like Step 1, this addresses the allowed current Event state within scanner family 4. The controller now reads the resolved Event's current status from the primary database and requires exact `Event::STATUS_ACTIVE` before input validation, QR lookup or scan writes. Archived/cancelled Events receive framework 403 without Guest/token disclosure or new scan history. Event resolution, identity/role checks and the earlier offline middleware remain in place.

Verification used synthetic memory-SQLite data, TEMP storage and process-local array/null integrations:

- Original controller with the new verification tests: **25 tests, 9 intended failures, 16 passing controls**, zero errors/skips. Inactive Events previously returned 200 for valid passes, 404 for unknown tokens and 422 for missing input; a stale cached Event and inactive selected/default fallback also bypassed the intended 403.
- Corrected controller: **25 tests / 159 assertions**, zero errors/failures/skips. Coverage includes exact nested Guest aliases/canonical token, active valid/unknown/malformed/revoked/cancelled/fully-used results, archived/cancelled Events with valid/unknown/missing input after existing history, status cancellation after middleware cached an active Event, persisted identity/role revocation and reassignment, foreign session/client Event overrides, selected/default unassigned fallback and active-input validation. Refused authority cases preserve complete Guest/QR/Admission/Checkin/ScanLog snapshots; allowed verification preserves Guest/QR/Admission data and only adds the existing history rows.
- Focused scanner/QR regression gate: **97 tests / 794 assertions**, zero errors/failures/skips. Filter: `ScannerQrVerificationTest|ScannerQrValidationTest|ScannerConfirmAdmissionTest|ScannerManualSearchTest|ScannerInterfaceTest|AdmissionScannerTest::test_scanner_allows_partial_use_and_blocks_over_limit|AdmissionScannerTest::test_revoked_qr_code_cannot_be_admitted|MultiEventSupportTest::test_scanner_cannot_validate_or_admit_qr_from_another_event|OfflineEventLockTest::test_active_offline_session_blocks_(laravel_qr_and_manual_admissions|scanner_validation_routes_without_logs)|AdminQrCodeGenerationTest`, with `--no-coverage --do-not-cache-result`. Full application suite not rerun. Initial TEMP runner package/service cache-path setup errors were corrected before the valid original-source observation; repository bootstrap caches remained byte-identical.
- PHP lint and Pint `--test` pass for the controller and new test file. Independent bounded read-only **AI** review: **APPROVE**, no material issues within Step 2.

Changed for this step: `app/Http/Controllers/ScannerController.php`, new `tests/Feature/ScannerQrVerificationTest.php`, and this plan. Existing worktree changes are retained. Evidence: `%TEMP%/p4048-step2-20261111-e1561fd782f2442486117cf5c9a74891/{before,after,regression}.xml`, matching logs, runner and preservation fingerprints.

Limits: this is an entry-time status check, not locked authority through scan writes or response delivery. Canonical persisted assignment, global QR lookup/lineage, write-time revocation and remaining scanner paths are still open. The existing unassigned selected/default fallback remains unchanged. P4-047/N1-N5 are not reopened. No real-data, MySQL concurrency, deployment, private QR migration, UI or Android work occurred. **P4-048 IN PROGRESS; Phase4 IN PROGRESS; production readiness NOT VERIFIED.** `Harusi-Codex-Continue-4h` was read-only confirmed **Disabled** and left unchanged.

At the Step 2 handoff, the next action was the active-Event authorization boundary on `POST /scanner/admit`. The explicit Step 3 request continues that action below.

## Task P4-048 - Step 3 COMPLETE (SOURCE LEVEL) (2026-11-11)

Scope: **`POST /scanner/admit`, `ScannerController::admit` only**, continuing P4-048 without restarting Steps 1/2. Inspection covered its route, directly called helpers/offline service and existing admission tests. The route retains session/role/HTTPS checks, throttle and offline middleware. Request validation precedes the existing three-attempt admission transaction. Previously, that transaction locked Event then offline Session, QR and Guest, but `OfflineEventLockService::assertLaravelAdmissionAllowed()` checked only the offline lock, not Event lifecycle. The empty canonical-token branch also wrote scan history before the transaction.

Correction: capture the scanner identity, nullable assignment and explicit assigned/selected Event ID, using default Event only for a genuinely unassigned scanner without an explicit selection. Inside each existing transaction attempt, require a positive integer/string Event ID, read and exclusively lock the current primary Event and require exact active status. Missing explicit Events return framework 403 instead of falling back. Then lock the current primary scanner and require an active scanner account with the same nullable assignment. Continue through the existing offline service and unchanged QR/Guest admission rules, comparing Guest ownership and recording wrong-event history against the captured Event. Relative lock order remains **Event → scanner → offline Session → QR → Guest**; the offline service re-locks the same already-held Event row. Event/scanner locks remain held through admission writes. Empty canonical-token logging now occurs inside this guarded transaction, retaining its active-Event 422 payload.

Focused evidence, using synthetic memory SQLite, TEMP storage and process-local array/null integrations:

- Original controller with the initial boundary class: **23 tests, 18 expected failures, 5 passing controls**, zero errors/skips. It admitted after verify→archive/cancel, admitted with authority revoked at transaction entry, and could admit a default-Event pass through a missing explicit Event pointer. Missing Event at transaction entry returned 404; inactive invalid-token requests still wrote rejection history.
- Final expanded boundary: **28 tests / 144 assertions**, zero errors/failures/skips. Tests preserve complete Guest/QR/Admission/Checkin/ScanLog snapshots after verify→archive/cancel, lifecycle change or Event removal at transaction entry, missing assigned/selected pointers, scanner reassignment/unassignment/deactivation/role revocation at transaction entry, inactive unknown/empty tokens and malformed context introduced after middleware resolution. Existing successful history is preserved. Active selected/default fallback, numeric-string selection, invalid-token responses and ordinary before-request reassignment's 409 with anonymous zero-entry history remain compatible. Transaction-entry callbacks demonstrate that a preliminary-only check would not protect the write.
- Final affected regression gate: **63 tests / 454 assertions**, zero errors/failures/skips. It includes the new boundary, all `ScannerConfirmAdmissionTest` and `ScannerManualSearchTest` cases, selected partial/over-limit/revoked admissions, both cross-Event and assigned-Event admission controls, and both existing offline validation/admission blocking cases. Single/double/family counts, stale allowance, manual codes/rules, response/history behavior and offline 423 precedence pass. All 34 overlapping cases retain Step 2's exact per-case assertion counts; Step 2's class/gate was not rerun. Full application suite not run.
- PHP lint and Pint `--test` pass for both changed PHP files; controller whitespace check passes. Independent bounded read-only **AI** review: **APPROVE** after fixing a non-scalar selected-ID concern before `whereKey()`; the expanded context tests and numeric-string control pass. No further blocking issue found within Step 3.

Files changed: `app/Http/Controllers/ScannerController.php` (only the `User` import and `admit()`), new `tests/Feature/ScannerAdmissionAuthorizationTest.php`, and this plan. All other controller methods, routes and shared services remain unchanged, including Step 1/2 and manual admission production code. Existing worktree changes are retained. Evidence: `%TEMP%/p4048-step3-20261111-767bbbc3432e403f8cf6c4e6da4c2d5c/`, including original/final JUnit reports, logs, source snapshots and preservation fingerprints.

Limits: SQLite establishes the refusal, transaction composition and data-conservation behavior; it does not prove actual MySQL lock waits/concurrency. Shared/manual scanner paths, broader QR lineage/read/log requirements and remaining family-4 decisions remain open. No real-data, deployment, private QR migration, UI, Android, dependency or scheduling work occurred. **Step 3 COMPLETE (SOURCE LEVEL); P4-048 IN PROGRESS; Phase4 IN PROGRESS; production readiness NOT VERIFIED.**

Step 3 confirmation against the full pasted request (2026-11-11): the existing production correction and tests remain unchanged. Rechecked the isolated affected gate: **63 tests / 454 assertions**, including admission boundary **28 / 144**, zero errors/failures/skips; PHP lint and Pint pass. No new tests, production edits, full-suite run or additional review cycle. The existing independent approval is retained. `Harusi-Codex-Continue-4h` is read-only confirmed **Disabled**. Evidence: `%TEMP%/p4048-step3-request-recheck-20261111-9f69aa024dd94886b849443c62b71602/focused.xml`. **Step 3 remains COMPLETE (SOURCE LEVEL); P4-048 and Phase4 remain IN PROGRESS.**

At the Step 3 handoff, the next small action was `GET /scanner/manual-search`. The explicit Step 4 request continues that action below.

### P4-048 Step 4 - `GET /scanner/manual-search` FIXED (SOURCE LEVEL) (2026-11-11)

Scope: **`GET /scanner/manual-search`, `scanner.manual-search`, `ScannerController::manualSearch` only.** Inspection covered the route and its middleware, the method, `PhoneNumberService`, the view and the existing manual-search tests. Steps 1-3 were not reopened.

Observed issue: guest results were already scoped to one Event and client `event_id` was ignored, and identity, role and assignment were already re-read from the primary database on every request by `EnsureActiveAccount` and the role middleware. However, the method never checked the Event's lifecycle, so archived/cancelled Events stayed searchable. Through the shared `scannerEvent()` resolver, a missing assigned or selected Event (`users.event_id` has no foreign key) silently became the default Event, exposing another Event's guests. A malformed selection could resolve to Event 1 (`true`), the default Event (`0`, non-numeric) or a 500 (array).

Correction, inside `manualSearch` only: before reading `q`, resolve the Event with the rule Step 3 approved for `admit()`: assigned Event, else the session selection, and the default Event only for a genuinely unassigned scanner without a selection. Require a positive integer/numeric-string ID, read that Event from the primary connection and require exact `Event::STATUS_ACTIVE`; otherwise return the framework 403. The guest query and the page header use this verified Event instead of calling `scannerEvent()` again. `scannerEvent()`, every other method, routes, the view, phone/manual-code matching, the 12-result limit and the displayed fields are unchanged. The search remains read-only and is still not subject to the offline lock.

Focused evidence (synthetic memory SQLite, TEMP storage, array/null integrations):

- Original controller with the new class `ScannerManualSearchAuthorizationTest`: **24 tests, 15 failures, 9 passing controls**, zero errors/skips. Failures: six archived/cancelled searches (code, phone, empty query) returned 200; a status change behind a cached identity relation returned 200; missing assigned/selected Events returned 200 with the default Event's guest; malformed selections returned 200 or 500; inactive selected/default fallback returned 200.
- Corrected controller: **24 tests / 212 assertions**, zero errors/failures/skips. Refused requests return 403 (or the login redirect for unauthenticated/deactivated identities), show no guest name, phone or admit form, and leave Guest/QR/Admission/Checkin/ScanLog snapshots identical, including history created by an earlier manual admission. Controls: code and phone searches are read-only; role revocation to admin/customer is 403; persisted reassignment shows only the current Event despite a stale identity, session and client `event_id`; an active assignment ignores a foreign inactive session/client Event; the unassigned selected (numeric string) and default fallbacks still work for active Events.
- Affected regression gate: **134 tests / 1,026 assertions**, zero errors/failures/skips. It contains the new class, all of `ScannerManualSearchTest` (21 / 217), `ScannerInterfaceTest`, `ScannerConfirmAdmissionTest`, `ScannerQrValidationTest` (20 / 84), `ScannerQrVerificationTest` (25 / 159), `ScannerAdmissionAuthorizationTest` (28 / 144), and the selected cross-Event, offline-lock, customer-denial and manual-code QR cases that touch this route. Full application suite not run.
- PHP lint and Pint `--test` pass for both PHP files. Fingerprints of 553 project files: only `ScannerController.php` changed and the new test file was added before this plan edit; bootstrap caches are byte-identical. No independent review was requested or run for this step.

Files changed: `app/Http/Controllers/ScannerController.php` (`manualSearch` only), new `tests/Feature/ScannerManualSearchAuthorizationTest.php`, and this plan section. `ScannerManualSearchTest.php` was not edited; its pre-existing Pint findings are left as they were. Evidence: `%TEMP%/p4048-step4-20261111-ebba30196d5148bba1d74c2aaa481ba6/{before,after,regression}.xml`, logs, the original controller copy and fingerprints.

Limits: this is an entry-time check for a read; a change after the Event read is not observed by that request. The Event rule is duplicated from `admit()` rather than shared, because `admit()` was out of scope. `POST /scanner/manual-admit` still uses `scannerEvent()` without a lifecycle check, so an inactive Event can still be admitted manually and its redirect back to search then returns 403. Dashboard, scan page and recent-scans reads are also unchanged. SQLite only; no MySQL, host, device or real-data check. `Harusi-Codex-Continue-4h` read-only confirmed **Disabled**. **Step 4 FIXED (SOURCE LEVEL); P4-048 IN PROGRESS; Phase4 IN PROGRESS; production readiness NOT VERIFIED.**

Next small action: `POST /scanner/manual-admit` (`scanner.manual-admit`, `ScannerController::manualAdmit`). Not started; no automatic continuation.
