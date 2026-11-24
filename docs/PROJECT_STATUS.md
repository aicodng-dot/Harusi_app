# Harusi project status

Additive UI task UI-REF-001 is COMPLETE as reference documentation only (2026-10-30), documenting all 39 approved PangaEvents references. Approved-design implementation and screen QA remain incomplete; Phase4 backend authorization is active. The latest completed source task is P4-047, verified at 2,579 tests / 46,472 assertions on 2026-11-11. [39-screen plan](REFERENCE_UI_IMPLEMENTATION.md).

## CURRENT - 2026-11-24 (customer experience)

- **Adding guests no longer needs Excel.** A new Add Guests page offers: type guests (several rows, "Add another guest"), paste a list of names (one per line, e.g. from WhatsApp), paste names with phone / pass / number of people in any order, Excel/CSV upload, and picking phone contacts (Chrome on Android). Every method shows an editable review before anything is saved. A phone number is now optional.
- **Excel/CSV import** accepts everyday column names, needs only a name column, offers Excel and CSV templates, and can send every row (including problem rows) to the editable review.
- **Navigation (app pattern):** phones get a slim top bar (back, event name, ⋯ menu) and bottom tabs. Inside an event the tabs are Home, Guests, a raised + Add, Card and Invites; outside an event they are Events, + New and Account. The ⋯ sheet holds event details, setup, designs, switch event, account, help, language and sign out. Focused task screens hide the tabs; the guest bulk bar appears over the tabs only while guests are selected. Computers get a sidebar with the same names, a New event button and breadcrumbs. There is also a Help page. Desktop pages are no longer phone-width; the card editor shows the preview beside the controls.
- **My Events overview:** totals (events, guests, cards, admissions), quick actions and recent activity.
- **Cards:** a Featured collection of 10 design families; guest names print larger. Cards made before this change show as "outdated" until re-created (QR codes do not change).
- **Guest list:** search as you type (name, phone, invitation code, pass word), filters (pass type, invitation card, sending, admission, phone, date added, people per pass, entries left), sorting, pages of 50, select all matching guests, and bulk delete (admitted guests are kept). Desktop shows a table; phones show compact cards.
- **Share invitations (no paid messaging):** a new Share tab per event. Customers choose guests (not shared yet, all, by pass type, or a selection from the Guests page), pick WhatsApp, SMS or the phone's share menu, and go through guests one at a time: each tap opens WhatsApp or Messages with the guest's number and a personal message containing their own invitation link; the customer presses Send, comes back and taps "I sent it". Progress is saved (pause and continue later). Also: share one guest from the Guests list, reusable message templates, a sharing history, and a guest-facing invitation page (/i/…) showing that guest's card with its QR. Statuses are only "sharing started", "you confirmed sent" and "skipped" — never delivered or read. Nothing is sent automatically.
- **RSVP:** each guest's invitation page now asks "Will you attend?" (Yes / No, how many people for Double/Family passes, optional message). Guests can change their reply until replies close (owner's deadline, or the end of the event day). The owner sees attending / not attending / awaiting, people expected, the messages, can record replies received by phone, change the settings and download a CSV. The dashboard "Confirmed" card and the setup checklist's RSVP step now use real replies; the guest list can filter by reply. Replies never change passes, QR codes or admission.
- **Testing:** full suite 3,045 tests passing (zero failures); browser checks at 6 screen sizes on a copy of the database. Not checked on real phones. [Details](PROJECT_PLAN.md#customer-experience--2026-11-24).

## Previous - 2026-11-23

- **Scanners for customer events:** administrators can now assign a gate scanner to a customer's event. That scanner admits only that event's guests, with the usual limits and duplicate protection. Offline (Android) sessions are still available only for admin events.
- **Admin → Customers:** a new section to find customers, see their events and guests, edit their details, suspend or reactivate them, and delete customers who have no events. Every action is recorded in an audit log.
- **Customers can now:**
  - see and edit their own profile
  - edit their events
  - delete events that have not been used at the gate
  - edit and remove their guests (admitted guests are protected)
- **Offline package signing:** ready, but it needs the owner to generate and install the real signing key. See [OFFLINE_SIGNING_KEYS.md](OFFLINE_SIGNING_KEYS.md).
- **One additive database table:** `admin_audit_logs`.
- **Testing:** checked with automated tests and on a copy of the MySQL database. Not checked on real phones, real hosting or real WhatsApp. [Details](PROJECT_PLAN.md#feature-batch--2026-11-23-p4-048-online-assignment-offline-signing-tooling-admin-customer-management-customer-self-service).

## Previous - 2026-11-11

P4-047 is **COMPLETE (SOURCE LEVEL)**. Its four implementation parts and the F1/F2 frozen-context corrections pass final combined verification. Final independent AI review: **APPROVED WITH NONBLOCKING FOLLOW-UPS**; this was not human review. Phase4 remains **IN PROGRESS**.

- Part 1 (2026-11-07): scanner activity screens and downloads for the selected event no longer show the current name or phone of a guest, or the name of a scanner, who now belongs to a different event; those rows keep their own time, result and gate and show "Unknown" instead. Searching scanner activity only matches guests of the selected event. The notification log only lists messages that belong to the selected event. Saved import results only appear for the person and event that produced them; anyone else sees a short note to upload the file again. CSV and Excel downloads are fully prepared before they are sent and tell browsers and caches not to keep a copy. The offline-session banner only names an allowed creator. An independent reviewer checked part 1, and the fixes it asked for are done.
- Part 2 (2026-11-08): QR images are now kept in a private folder instead of the public storage folder. They are sent only to a signed-in administrator of the right event, always complete, and marked so browsers and caches do not keep a copy. A QR file name that is unsafe, or that another guest's QR also uses, is refused instead of shown or repaired. A web-server rule blocks the old public QR web address on Apache hosts. An independent reviewer checked part 2 and asked for fixes, which are done and were checked again. As a result, events named like a Windows device (for example "Con") can still get QR images, and the protected folder is checked more strictly.
- Part 3 (2026-11-08): the top bar of every admin page is prepared before drawing, using fixed event, switcher and offline-banner values without drawing-time queries. Family-3 pages tell browsers and caches not to retain a copy. Shared locks replace exclusive layout locks; concurrent same-event writers can still wait.
- Part 4 (2026-11-09): each family-3 page and download reads its content and top bar in one protected step, so a mid-read selection change cannot mix Events. Moved Guests are refused and views receive fixed values without the full QR credential. Selected synthetic MySQL/MariaDB checks verified the shared-lock read/delivery changes. Those delivery checks bypassed the image preflight, whose exclusive-lock concern remains N1; the overall QR/scan lock issue is not fully resolved. A large page held locks for about 1.2 seconds in one selected measurement; that is not an upper bound or a QR/import/export measurement.
- Important before deploying: this code does not move the existing QR images. Until an operator creates the private folder and copies the old images into it using the planned, checked procedure, QR preview, download, ZIP and generation stop with a clear error message. Adding a single guest (which always makes a QR), importing with "generate QR" and deleting a guest who has a QR image also stop, and nothing is saved or removed. Old public images are not deleted. The same applies on a developer machine until the private folder exists. [Deployment step](DEPLOYMENT_CPANEL.md).

F1 PASS: import results stay with the actor/Event captured before the read; mismatches are cleared and correctly bound results still appear. F2 PASS: all four lists keep their captured page, URL and query; Guest history counts match that page and authorization is unchanged. Focused checks pass **291 tests / 1,801 assertions**. The full suite ran **once** and passed **2,579 tests / 46,472 assertions**, with zero errors/failures/skips. All 2,567 prior cases keep exact assertion counts; 12 new cases add 143 assertions. No source changes were made during closure. [Final evidence](PROJECT_PLAN.md#p4-047-final-verification-and-source-closure-2026-11-11).

P4-034 now removes an earlier preview file after a known database rollback only when the same operation actually retries, current permission checks pass and no current import references that file path. If permission is lost, it leaves the earlier file untouched. Failed cleanup stops before another put, but the file outcome can remain uncertain if an adapter acted before reporting failure; separate recovery is still needed. This is a limited source repair, checked with invented data and fake private files. [Details and limits](OFFLINE_RETRY_ARTIFACT_RECOVERY.md).

Harusi already has an administrator website and a browser scanner for events, guests, QR codes, manual entry and admission history. Work is continuing toward customer workspaces. A customer workspace home and a simple form for creating an empty workspace are now prepared in source. Its creator automatically becomes the owner; customer event/guest management and public signup remain closed.

The latest completed source task is **P4-047 - whole legacy read/export/private-media family**. P4-045 issuance and P4-043 deletion keep their previously accepted scopes and explicit file/SQL/caller/commit limits. Scanner current authority remains open. [Implemented issuance](LEGACY_GUEST_ISSUANCE.md).

| Area | What is present in source | What is still unverified or unfinished |
|---|---|---|
| Sign-in and recovery | Shared email/phone sign-in, active-account checks, remember sessions and email password recovery | Real hosting, SMTP delivery and deployed database migrations |
| Device authentication | Three token-authentication routes: login, profile and logout | Android app source/device integration and versioned scanner/admission APIs |
| Existing scanner | QR/manual browser admission, capacity checks and transaction history | Real camera/device experience, target-host load and online request idempotency |
| Workspaces | Customer sign-in, own-workspace home and automatic owner creation through a protected setup form; selected owner/manager workspace/event access prepared internally | Complete runtime tenant policies, customer account creation, event portal and onboarding; legitimate mapping only for existing legacy events |
| Database checks | Selected synthetic MySQL migration, contention, rollback, restore and visibility rehearsals | The actual customer database/schema, private-file restore and deployment process |

## IMPLEMENTED — verification so far

The latest complete backend regression passed **2,579 tests / 46,472 assertions** (P4-047 final verification, 2026-11-11). All 2,567 slice-4 cases retain exact assertion counts and 12 F1/F2 cases are added. Earlier conservation of the 2,288 P4-045 cases retains the single documented +1 query-count check. Tests used invented data, memory SQLite and TEMP/fake storage. No production/staging database or real QR file operation occurred; earlier selected engine evidence was not rerun. [Final evidence and limits](PROJECT_PLAN.md#p4-047-final-verification-and-source-closure-2026-11-11).

Historically, P4-032 passed **3 cases / 269 assertions** in TEMP while retaining the then-current P4-031 **1,884 tests / 36,095 assertions** gate by preservation. It changed no runtime cleanup. P4-033 then reviewed the design; P4-034 now supplies the bounded repair and its new passing source gate. These steps do not establish deployed or complete file recovery. [Probe and regression evidence](TEST_STRATEGY.md).

Selected real-engine checks passed for backfill contention/recovery, all four QR/manual last-entry pairings, an interrupted admission rollback and 34 authority-reader observations. These checks establish the recorded test cases; they do not establish a live deployment. A separate MySQL follow-up also passed 16 demo-seeder cases, including failed-insert rollback and safe refusal on existing sample rows. These used invented data; they did not reset real accounts. [Seeder engine checks](MYSQL_DEMO_SEEDING_REHEARSAL.md). The current source registers **100 routes** and the inventory catalog contains **32 tables**.

CSV downloads now guard formula-looking cells during export; stored records remain intact. The added TAB is visible to raw CSV consumers, and actual spreadsheet-client behavior remains unverified. [CSV format](CSV_EXPORTS.md).

The default demo seeder now refuses outside exact local/testing environments and refuses existing sample-data rows instead of resetting them. Samples share one transaction. The separate legacy recovery --apply path also now refuses outside exact local/testing environments. Existing demo accounts and any real recovery/provisioning still require their own review. [Recovery script scope](RECOVERY_SCRIPT_SAFETY.md). [Demo seeding scope](DEMO_SEEDING.md).

The root portable-backup directory is now excluded from ordinary Git adds. Existing backup contents were not opened or changed; this does not prove that a backup can be restored or that copied deployment packages exclude it. [Backup scope](BACKUP_AND_ROLLBACK.md).

Four separate MySQL recovery/token cases also passed using eight workers: simultaneous resets, reset versus old-password login in both orders, and an interrupted-reset rollback followed by retry. These used invented accounts and did not change real credentials. [Recovery engine checks](MYSQL_RECOVERY_REHEARSAL.md).

Latest connection check (2026-10-21): Harusi still could not connect to its configured database (safe error 2002). No customer records were inspected and no database or service was changed. The temporary MySQL rehearsals described above are separate from that configured database.

## REQUIRES VERIFICATION — before changing real event ownership

The earlier request for a “connection” and a “manifest path” meant the following practical information. You do not need to know those technical names.

1. **The intended testing copy:** which copy of Harusi and its database should be used for the real-data review, and who operates it.
2. **Who owns each existing event:** the event and the existing account of the person or organization that should manage it. A scanner assignment or an old administrator role does not establish ownership.
3. **Who operates the whole platform:** the specific existing staff accounts that should receive platform access, reviewed separately from event ownership.
4. **A backup that has been restored successfully:** both the database and required private files must be recovered in a separate testing location and checked. Making a backup file alone does not prove recovery.
5. **A controlled change window:** how existing writes will be paused and checked while real ownership changes are applied.

New workspace creation will be self-service: its authenticated creator becomes responsible automatically, without developer-prepared files. The transaction and protected customer form are implemented and tested in source; broader event authorization is still being built. The checks above apply to changing existing legacy ownership. Unknown owners remain private; names or administrator/scanner roles never establish responsibility. Secrets and private backup contents stay out of source control.

## PLANNED — next release boundaries

Phase 3 is still in progress. Runtime tenant authorization must be designed and tested before customer registration or full customer event/onboarding portal access. Manager permissions, platform support access, ownership transfer and last-owner rules also need explicit contracts. The existing global administrator interface is not a customer workspace interface.

The finite exit audit, P4-043 deletion and P4-045 issuance source boundaries are complete. Two runtime families remain: reads/exports/media and scanner authority. P4-046 completes the read/export/media design; its implementation and storage cutover remain separate. Work continues toward tenant enforcement and the customer portal. The person, organization or planner creating a new workspace will own and manage it automatically. Applying ownership to existing customer data still needs reliable ownership and recovery evidence.

Technical details: [project plan](PROJECT_PLAN.md), [Phase 3 report](PHASE_03_REPORT.md), [authentication API](API_CONTRACT.md), [scanner](SCANNER_ARCHITECTURE.md), [database checks](DATABASE_CONVENTIONS.md) and [backup/recovery](BACKUP_AND_ROLLBACK.md).

## IMPLEMENTED — next source step

P4-001 now checks selected workspace/event permissions internally: owners manage their workspace/events, managers handle the recorded event operations, and a foreign or unowned event fails the check. No customer screen or existing route uses this yet. All resources/files and existing global-admin paths need isolation before customer exposure. [Details](WORKSPACE_ACCESS.md).

## IMPLEMENTED — guest and pass ownership preparation

P4-002 adds internal checks that a requested guest and QR pass belong to the exact event/workspace, including rejecting a pass for a different guest in the same event. The checks are tested and remain internal; customer screens and existing file/routes are not yet connected. [Details](GUEST_ACCESS.md).

## IMPLEMENTED — customer sign-in and read-only home

P4-003 adds a separate customer web mode with shared email/phone sign-in, remember/recovery and a protected list of only its own active workspaces in English/Kiswahili. It cannot use staff/scanner areas or device tokens. Existing staff account management cannot expose/rewrite these customer identities. No real customer account was created or changed, and public signup/setup/event writers remain closed. 1,071 tests / 20,461 assertions passed in isolated tests. [Details](CUSTOMER_WEB_IDENTITY.md).

## IMPLEMENTED — legacy event list and direct controls

The old event list/switcher and direct metadata edit/archive/cancel controls now exclude workspace-owned events. Ordinary legacy events still work. This is one part of isolation: selected-event guest/scanner/offline/file paths still need their own boundaries, so customer event writers and public signup remain closed. 1,110 tests / 21,253 assertions passed in isolation; no real data was changed. [Details](LEGACY_EVENT_ADMINISTRATION.md).

## IMPLEMENTED — selected event and import context

Legacy administration now rejects invalid or workspace-owned selected events and direct offline setup targets before using them. It no longer creates or switches to a default event when the current selection becomes invalid. Changing events discards old import previews. These checks passed in isolation; downstream write/file safeguards, scanner/account boundaries and public customer setup remain unfinished. 1,172 tests / 21,736 assertions passed. [Details](LEGACY_SELECTED_EVENT.md).

## IMPLEMENTED — legacy staff account protection

The old staff screen now hides and rejects accounts connected to customer workspaces or platform access. Scanner assignments use active unowned events; an empty choice is selected automatically only when exactly one eligible event exists. Account changes recheck their protected state inside the write transaction. 1,240 tests / 22,336 assertions passed in isolated tests. Remaining scanner/offline/file safeguards and real rollout checks stay open. [Details](LEGACY_STAFF_ACCOUNTS.md).

## IMPLEMENTED — staff checks on a separate MySQL server

Four additional tests using nine independent workers checked staff event/account changes while another transaction was running, including membership changes and interruption rollback. These passed with invented data on a temporary MySQL server; the server/workers are stopped and generated plaintext credentials removed. The last complete backend suite remains 1,240 tests / 22,336 assertions, preserved because application source did not change. Real customer database/ownership/recovery and remaining scanner/offline/file work stay open. [Details](MYSQL_STAFF_REHEARSAL.md).

## IMPLEMENTED — automatic new-workspace ownership on MySQL

The internal automatic setup passed 10 synthetic MySQL cases using 13 independent workers. A new workspace and active creator-owner commit together; repeated requests avoid duplicate setup, and interrupted inserts roll back. Same-named legacy workspaces are never adopted. These checks used invented data; the temporary server/workers are stopped and generated plaintext credentials removed. Customer setup screens are the next bounded step; event/guest writers and public signup remain closed. The complete backend source gate remains 1,240 tests / 22,336 assertions. [Details](MYSQL_WORKSPACE_PROVISIONING_REHEARSAL.md).

## IMPLEMENTED — customer workspace setup

Existing customers can sign in, choose Create workspace, enter a name and automatically own the new workspace. A person, organization or planner needs no developer ownership file. Repeating the same request avoids duplicate setup. Event/guest management and public signup are still closed. No real customer database or ownership was changed. [Details](CUSTOMER_WORKSPACE_SETUP.md).

## IMPLEMENTED — private offline parent checks

Offline admin actions now recheck the exact selected unowned Event before loading related information or reaching validation/file/audit work. Private or inconsistent parent chains are hidden. Checks inside later write transactions and file operations are still being completed; this is not a deployed customer offline feature. [Details](LEGACY_OFFLINE_RESOURCES.md).

## IMPLEMENTED — package generation checks

New legacy offline packages recheck the unowned Event and eligible administrator/scanner accounts while holding transaction locks. Private workspace/platform scanners are excluded from choices and request validation. Failure checks preserve existing data and clean the newly written fixture file. Selected actual MySQL concurrency checks remain next; later offline/file operations and customer event/signup remain closed. [Details](LEGACY_OFFLINE_PACKAGE_GENERATION.md).

## VERIFIED — package generation on isolated MySQL

Six groups of checks passed using invented data on a separate MySQL server. They verified that package generation waits for ownership/staff changes, refuses ineligible scanners and rolls back its rows and new file after a database failure. Nine workers and four exact row waits were checked; all temporary processes and plaintext credentials are cleaned up. This does not establish a deployed customer system. Later offline actions and customer event/signup remain unfinished. [Scope](MYSQL_PACKAGE_GENERATION_REHEARSAL.md).

## IMPLEMENTED — session cancellation and closing checks

Existing administrator cancellation/closing now rechecks the event, account and session before changing status or writing audits. Changed ownership or lost permissions refuse; failed audit writes undo the action and return generic feedback. The new 110 cases and complete backend regression pass. Actual MySQL schedules for these actions and other offline/file paths remain separate. [Details](LEGACY_OFFLINE_SESSION_ACTIONS.md).

## IMPLEMENTED — package download checks

Package downloads now recheck current event/account/session/package access while preparing the file response and download audit. A failed download does not recreate or remove an already-generated package; retry uses that existing package. The complete backend regression passes. File deletion, real client delivery and other offline operations still need their own checks. [Details](LEGACY_OFFLINE_PACKAGE_DELIVERY.md).

## IMPLEMENTED — archive metadata checks

Archiving a package/results record now rechecks access and writes its archive metadata and audit together. These actions do not open, move or delete file bytes. Controlled precommit audit failures undo their archive update; uncertain commit and after-commit failures remain separate limits. Secure deletion and other offline actions remain separate work. [Details](LEGACY_OFFLINE_FILE_ARCHIVES.md).

## IMPLEMENTED — package-generation identity consistency

Direct package-generation checks now refuse ambiguous or changed account identities before reading event/scanner rows. Valid matching identities retain existing generation behavior. The full backend regression passes 1,657 tests / 26,554 assertions. [Details](LEGACY_OFFLINE_PACKAGE_GENERATION.md).

## IMPLEMENTED — deletion failure feedback

The existing file-deletion actions now refuse to report success when storage reports a failed deletion. Missing-file and successful deletion behavior remain compatible. File recovery after later database failure and current ownership locks remain separate work. [Details](OFFLINE_SYNC.md).

## IMPLEMENTED — private deletion errors

The two existing deletion actions now show a generic error when database or storage exceptions occur, keeping SQL and private paths out of those form errors. This changes feedback only; current ownership fences and file/database recovery remain separate work. [Details](OFFLINE_SYNC.md).

## IMPLEMENTED — private import/conflict errors

Result confirmation and conflict resolution now keep database details out of service-call form errors. Existing business guidance remains available. These feedback changes preserve the underlying import and conflict behavior. [Details](OFFLINE_SYNC.md).

## Historical P4-041 exit-audit checkpoint

P4-041 is complete as a documentation-only audit. Creation/QR repair, Guest deletion, reports/file delivery and scanner assignment checks remain four finite runtime families. The next proposed task is the design/characterization of single and bulk Guest deletion. P4-040 remains the latest source/test result; customer Event/Guest access, approved UI implementation and actual rollout remain unfinished. [Finite Phase4 exit register](PHASE_04_EXIT_AUDIT.md).

## Historical P4-042 deletion design checkpoint

P4-042 is complete as reviewed design and TEMP characterization (12 cases / 168 assertions). At that checkpoint, the code could lose QR files when database deletion rolled back or report success after a refused file/model deletion. The design defined a single-attempt current-set guard and clear localized failure feedback; it installed no repair. P4-043 was proposed next and P4-040 remained the main-suite/source result. The current P4-043 implementation and its retained customer/UI/rollout gates are recorded above and below. [Deletion design and observed outcomes](LEGACY_GUEST_DELETION_DESIGN.md).

## P4-047 source closure and remaining gates

P4-047 accepts family 3 at source level; the earlier family-3 OPEN checkpoint in the exit register is superseded by this closure. N1 QR preflight locking, N2 extreme/negative IDs, N3 unmeasured large import/export engine performance, N4 unnamed locking indexes and N5 additional boundary datasets remain [nonblocking follow-ups](PHASE_04_REPORT.md#p4-047-follow-up-register). They were not implemented or reinvestigated here. Scanner family 4, Phase4 and all 39-screen implementation/QA work remain unfinished. Real database verification, ownership backfill, private QR/media migration and old-copy denial, backup/restore, deployment and production-readiness checks remain pending; production readiness is **NOT VERIFIED**. `Harusi-Codex-Continue-4h` remains Disabled. Next task ID: **P4-048**. No task is started; stop after this closure.
