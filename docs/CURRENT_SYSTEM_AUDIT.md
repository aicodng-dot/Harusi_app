# Current system audit

## CURRENT — audit scope and evidence

Date: 2026-10-11 (Africa/Dar_es_Salaam). Reviewed existing source, routes, controllers, middleware, models, services, migrations, views/CSS/JS, tests, root README, notification-hook notes and portable-backup instructions. This is the working tree, including substantial pre-existing uncommitted/untracked work; Git HEAD alone does not represent the inspected app. No previous Tanzania Offline Shop project documentation was supplied, so the master request's stated discipline is used.

Commands/evidence: PHP 8.2.12 and extensions; Laravel 12.58.0; runtime route list (88); full isolated suite (197 tests, 196 pass, one fail); two temporary auth reproductions (2 tests/5 assertions); temporary Vite 7.3.3 build (passed). Details: [tests](TEST_STRATEGY.md), [route snapshot](CURRENT_ROUTE_REGISTRY.md), [report](PHASE_01_REPORT.md).

## CURRENT — modules and ownership

| Area | Verified behavior | Reuse / gap |
|---|---|---|
| Events/Admin | Global admin event CRUD/archive/cancel and selected session context; guests/QR/history/reports | Keep workflows; no customer tenancy |
| Users/Auth | Single users table, email/password web sessions, admin/scanner roles; scanner users have event_id/gate_name | Phone/status/locale/tokens/recovery absent |
| Guests | Event-owned records; pass type single/double/special, allowance up to10, used count and cancellation | AdminController validation/import reused |
| QR/manual code | Secure random token record, active/revoked state, downloadable PNG with visible four-digit code; event-scoped string uniqueness | QrCodeService, GuestQrImageService, ManualCodeService, canonicalizer |
| Imports/exports | XLSX guest template/read/preview/import; PNG/ZIP downloads, QR-list XLSX, CSV reports | GuestImportExcelReader/ExcelExportService; CSV formula handling gap |
| Admission | Scanner validation then confirmation/manual admission; scoped assigned event; transactions/locks/bounded updates, checkins/admissions/scan logs | Logic in ScannerController; offline applier separate; no shared AdmissionService |
| Offline | Server sessions, assignments, locking, encrypted signed package export, encrypted result validation/preview/confirm/conflict/audit/provenance | Rich server foundation; real Android/master/slave not verified |
| Notifications | Meta/null provider, normalization, configured templates, send logs/event listeners; webhook verification GET and acknowledgement POST | Hooks exist; signed delivery processing/retry/usage absent |
| Invitations | QR/manual-code pass image and DigitalCardReady notification hook | No studio/design renderer/template catalogue/background editor |
| Billing | Payment/pledge notification hooks only | No actual payment/pledge/meeting/billing modules |

Current ownership: events lack workspace ownership; `users.event_id` represents one scanner event; guests/QR/checkins/admissions/logs/offline records reference events or guests. Global administrators can operate every event. Selected event narrows queries but is not a tenancy boundary. [Domain](DOMAIN_MODEL.md), [database conventions](DATABASE_CONVENTIONS.md), [tenancy](MULTI_TENANCY.md).

## CURRENT — authentication and login problems

`AuthController` validates email/password, attempts session login, regenerates session and uses intended URL before role default. Logout invalidates session and regenerates CSRF. Login is throttled10/min. No public registration, phone login, forgot/reset controllers, API routes, token implementation or active-account model exists.

The current login view has no Admin/Scanner switch. Therefore an earlier switch-based problem cannot be reproduced from current code. Confirmed problems: remember flag triggers missing remember_token SQL error on fresh schema; scanner with intended admin URL redirects there then gets403. Credentials in the current demo form/README are a deployment risk. See [authentication](AUTHENTICATION_ARCHITECTURE.md).

## CURRENT — scanner/offline boundaries

The scanner website is operational in source/test coverage: dashboard, scan (`scanner/index.blade.php`), manual phone/four-digit lookup, count confirmation and recent scans. `scanner/scan.blade.php` is an unused placeholder and does not prove the scanner is missing. Web endpoints use sessions/CSRF, not Android bearer auth.

Missing/dangling assignment can fall back to session/default event. Event active state is not consistently enforced. Admission retry can count again when allowance remains. Offline event locks prevent incompatible server-side changes/admissions during active offline sessions. Package/result protocols, hashing/signing/encryption and import provenance have automated tests; offline PIN verifier uses predictable identifiers. No Android Java/Kotlin/Gradle/manifest found in this repo; no real Wi-Fi master/slave or device session behavior was tested. [Scanner](SCANNER_ARCHITECTURE.md), [offline](OFFLINE_SYNC.md), [Android](ANDROID_SCANNER.md).

## CURRENT — UI/localization review

Blade/Tailwind admin and scanner layouts, reusable CSS classes, JS camera/form handling, mobile scanner bottom navigation and horizontally scrolling admin mobile navigation pills exist. An admin drawer is a future design target. English text is hard-coded; local locale is en; no SW/EN translation catalogue or switcher exists. Design-system targets are not current implementation.

Source risks include admin tablet sidebar width280 at768 breakpoint; large fixed table/filter widths; 36/40px admin controls below requested touch size; narrow scanner count/result layouts; long translated labels not yet testable. Browser unavailable: no visual overflow, contrast, keyboard, real camera or nine-width bilingual verification claimed. [Design](DESIGN_SYSTEM.md), [responsive](RESPONSIVE_LAYOUT.md), [localization](LOCALIZATION.md), [journeys](USER_JOURNEYS.md).

## CURRENT — documentation contradictions

| Existing claim | Verified correction |
|---|---|
| README describes scanner name/phone search | Current manual lookup prioritizes phone/four-digit code; use actual scanner contract |
| README refers to offline scanner ZIP | Server package uses encrypted `.wqrpkg` and results `.wqrresults`; QR image ZIP remains separate |
| README suggests all concurrent gates prevented | Locks/bounded updates exist, but no real MySQL parallel last-entry verification or retry idempotency |
| `DigitalCardReady` / PaymentCompleted hooks | Notification preparation; actual card/payment/pledge/meeting modules absent |
| Docs folder formerly placeholder | Canonical/specialist documentation now created from this audit |

Root setup and hook documentation are retained; specialist references resolve contradictions. No runtime behavior was changed to make documentation appear correct.

## REQUIRES VERIFICATION — external state

Configured local MySQL (`127.0.0.1:3306`) could not be reached: QueryException code2002 on read-only probe, repeated with escalation. No live schema/table counts/duplicate inventory/server version obtained. Local env uses file sessions/cache, sync queue, log mail and development debug; this is not hosting evidence. `public/storage` is absent here. Actual cPanel, SMTP, HTTPS/proxy/root, cron/queue, backup restore, Android source/device and real concurrent admission require follow-up environments.

No secrets or guest PII were written to docs. No real DB migration/seeding, notification send, dependency update, deployment or production configuration change was part of Phase0/1.

## TARGET / PLANNED — migration conclusion

Preserve this application. Add shared email-or-phone identity/recovery/tokens, then workspace ownership/memberships, then policies/scoping before public self-service. Reuse current QR/phone/import/notification/offline foundations. Extract admission to one authoritative service and protect files. Build bilingual UI/customer/onboarding and invitation engine progressively; postpone billing. Detailed risks and decisions: [risk register](RISK_REGISTER.md), [migration](SAAS_MIGRATION_PLAN.md), [Phase2 tasks](phases/PHASE_02_AUTHENTICATION.md).
