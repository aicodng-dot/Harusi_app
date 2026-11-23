# Phase 0 and 1 report

## CURRENT — result and limits

Objective: document and audit the existing app without changing production behavior. Repository audit and baseline verification completed on 2026-10-11; documentation validation/status is recorded in [project plan](PROJECT_PLAN.md). External environment findings remain REQUIRES VERIFICATION.

1. **Current architecture:** Laravel12 monolith, Blade/Tailwind/JS, global Admin and session-authenticated scanner; configured MySQL, private offline files, Meta provider. [Architecture](ARCHITECTURE.md).
2. **Target SaaS:** public site, platform admin, workspace-based customer portal, web/Android scanner and one backend/identity; shared admission, authoritative invitations, future billing.
3. **Database ownership:** event IDs and one scanner user event_id; no workspaces or owner membership. [Domain](DOMAIN_MODEL.md).
4. **Authentication:** email/password, web guard, admin/scanner roles, remember UI; no phone/recovery/token API.
5. **Login problems:** isolated remember login throws missing-column SQL exception; scanner intended admin URL reaches403; no current role switch found.
6. **Reusable services:** phone/manual code, QR/pass image, XLSX reader/export, QR canonicalization, offline lock/session/audit/snapshot/crypto/signature/files/import, notification provider/logs. Admission/report/guest workflows partly remain in controllers.
7. **Scanner:** scan/validate/confirm/manual/recent flow, event scoping and capacity locks; no online request idempotency and unsafe assignment fallback.
8. **Android/offline:** server package/result implementation exists; native Android/network/master/slave/device behavior unverified. Predictable PIN verifier is a security gap.
9. **Invitations:** QR/manual pass PNG and digital-card event hook; no studio/renderer/catalogue/custom artwork editor.
10. **Security:** tenancy absent, remember defect, assignment/state gaps, public QR capabilities, replay/offline PIN/CSV/demo/backup risks, unsigned no-op webhook. [Risks](RISK_REGISTER.md).
11. **Tenancy migration:** inventory/backup/owner mapping, nullable additive ownership, resumable backfill, isolation tests, then tighten constraints. Do not invent ownership.
12. **Auth updates:** repair sessions/redirects, canonical optional phone/account state, generic email recovery and revocable device tokens. No paid SMS/WhatsApp OTP.
13. **Responsive issues:** source-based narrow control/table/sidebar risks; visual verification unavailable.
14. **SW/EN:** hard-coded English today; bilingual catalogues, locale persistence and longer labels planned.
15. **Hosting:** local PHP8.2.12/extensions checked, installed Laravel12.58.0; cPanel/SMTP/cron/web extensions/actual MySQL unknown; local mail logs only.
16. **Tests missing:** phone/recovery/token/account-state, tenant/files, real concurrent final-entry/retry, Android/offline interoperability, invitation decoder/overflow, signed callbacks and bilingual viewport/accessibility. [Test strategy](TEST_STRATEGY.md).
17. **Documentation:** four canonical files, requested specialist structure, route snapshot, four ADRs and Phase2 contracts; existing hook document preserved. [Index](README.md).
18. **Open decisions:** legacy owner mapping, phone/email collisions, support recovery, token expiry/refresh choice, actual Android protocol, secure offline PIN, private media delivery, hosting queues, retention and future commercial provider.
19. **Exact Phase2:** P2-001 sessions/remember/authorized redirects/HTTPS; P2-002 phone/account state; P2-003 broker-based email recovery; P2-004 secure token API. [Task contracts](phases/PHASE_02_AUTHENTICATION.md).
20. **Behavior preservation:** Phase0/1 edits are Markdown only. Existing dirty work preserved; no production migration, config/UI/API change, send or deployment. File hashes are compared at the phase gate before later coding.

## Verification

| Check | Result |
|---|---|
| Existing PHPUnit suite | 197 tests /1575 assertions;196 passed,1 HTTPS redirect failure |
| Temporary bug reproductions | 2 tests /5 assertions passed, confirming remember SQL error and wrong-area redirect |
| Temporary frontend build | Passed;82 modules;existing public/build preserved |
| Runtime version/routes | Laravel12.58.0;PHP8.2.12;88 routes |
| Live DB read-only probe | Failed2002;no data mutation/schema results |
| Browser/mobile/localization | Unavailable;source review only |
| Android/cPanel/restore/concurrent MySQL | Not executed;explicit gaps |
| Application/config/test/build hashes | All230 baseline files unchanged at Phase0/1 gate |
| Documentation paths/states | 43Markdown files,372local links;no broken references or missing state markers;whitespace check passed |

## Deployment and continuation

Database migrations: none. API/UI changes: none. Security/localization/responsive work: audit only. No deployment action needed for Markdown files. The user's later instruction authorizes continuing after this report without waiting for optional approval; Phase2 starts only after the audit/documentation gate. Future phase reports must separately identify runtime changes and their checks.
