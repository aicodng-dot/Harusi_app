# Phase 2 authentication report

## CURRENT — execution state

Phase 2 repository gate is **COMPLETE** on 2026-10-16. P2-001 through P2-005 are completed in source and isolated tests. Real-environment rollout gates below remain open. Detailed contracts/status: [phase tasks](phases/PHASE_02_AUTHENTICATION.md). Dated completion evidence: [project plan](PROJECT_PLAN.md).

## IMPLEMENTED — P2-001 shared web session foundation

Objective: restore reliable existing session authentication and safe login navigation.

- Created additive `2026_10_12_000000_restore_remember_token_to_users_table.php` and `AuthSessionFoundationTest.php`.
- Modified AuthController, User, bootstrap middleware ordering, web routes and existing login view. Pre-existing unrelated changes preserved.
- Database: nullable 100-character remember token; password hashes/IDs untouched. Migration tested in SQLite memory; actual MySQL schema rollout not executed.
- API: additive GET `/admin/login` and `/scanner/login`; common POST `/login`, logout and scanner JSON shapes remain compatible. Registered route count is 90 (audit baseline88).
- UI: labelled Kadify/staff/scanner entry pages, escaped session error feedback, autocomplete, public demo credential panel removed. No visual redesign or translated UI delivered.
- Security: role/same-origin intended destination validation; stale selected-event/import/old-account intent cleared; cookie replay/logout/rotation and HTTPS-before-auth tested. Role permissions remain unchanged. Seeded-account/backup risks still open.
- Tests: initial focused45/46; omitted incoming-cookie fixture corrected; affected2tests/30assertions passed; final complete suite **227tests/1,768assertions passed** (53.628s). Syntax and scoped whitespace checks passed.
- Regressions: original scanner HTTPS test failure resolved; no failure in the final suite. No real browser/device/hosting proof.
- Documentation: architecture, auth, API, database, risks, test strategy, public entry/journeys, project/task progress and this report updated. Historical Phase0/1 audit is retained.
- Deployment: no actual DB migration, dependency upgrade, message send or hosting action. Back up/rehearse before deploying the prepared migration.

## IMPLEMENTED — P2-002 email/phone and account state

Added nullable unique account phone_number, is_active default true and stored sw/en preferred_language default sw. LoginIdentifier reuses the existing phone service. Legacy email requests/storage remain compatible. Persisted state/role is refreshed before web authorization; inactive/deleted/unknown-role and remembered access is denied. Named login limits apply per IP and canonical identifier. Admin forms preserve omitted fields, normalize/uniquely validate phones, prevent own-account disabling and show actual inactive state.

Inactive scanners cannot receive new offline authorization through selection, validation or the locked builder; downloaded offline packages and historical result import/reconciliation are unchanged. No existing guest phones or ownership data were rewritten.

Created shared-identity migration, helper, middleware and three focused test files; 17 task files changed/new. Full suite **265tests/1,946assertions passed** (115.866s); corrected four test-fixture assertions passed before the full run. New-file Pint, syntax/whitespace and temporary frontend build passed. Real DB migration/deployment/mail/Android checks were not performed. Language preference is stored; UI translation is not delivered by this task.

## IMPLEMENTED — P2-003 recovery and session revocation

Added email/phone recovery to the registered email through Laravel's existing broker and deferred execution, forgot/reset forms and login link, generic response, independent request/reset limits and trusted APP_URL links. Log/composite mail transports are rejected; delivery failures log only safe class/reason. Reset explicitly hashes the password, rotates remember credentials and consumes the token transactionally without automatic login. Browser password HMAC validation follows persisted-account refresh; stale sessions/remember cookies are rejected while valid remember restoration works. Legacy sessions lacking the marker require one-time reauthentication.

Focused **40 tests / 228 assertions** passed (14.066s); full **305 tests / 2,174 assertions** passed (63.743s). Review fixed reset throttle-key confusion and hash-shaped-password handling. A guarded remember-token fixture was corrected before the passing regression. Pint, syntax, whitespace, 94-route registration and temporary Vite build (82 modules, 6.17s) passed. Tests used SQLite memory, test mail/HTTP and temporary storage. No schema/dependency change, real message send or deployment occurred. API token revocation will be integrated in P2-004; historical offline packages/imports remain unchanged.

## IMPLEMENTED — P2-004 device-token authentication

Added Sanctum4.3.3 only, token migration/relation, HTTPS bearer login/me/logout, fixed24-hour expiry, ten-token bound, narrow abilities and explicit safe response serialization. Browser cookie/CSRF auth stays separate. User-row transactions serialize credential checks/token issuance with recovery/admin revocation. Scanner API identity requires an explicit active event; logout remains available after event access is lost. Account/gate/role changes and credential rollback are covered.

Full isolated regression passed **353 tests / 3,049 assertions** (124.386315s), including **48 tests / 875 assertions** in DeviceTokenAuthenticationTest. New-file Pint, 15 PHP syntax checks and scoped whitespace checks passed. Runtime registers97routes: the prior94 plus the three versioned auth endpoints; no Sanctum CSRF-cookie route is enabled. Tests use SQLite memory, fake notifications/HTTP and temporary Laravel storage. Existing public/build, .env and unrelated checked source are preserved. Review repaired gate-only revocation and API419 finalization. An event-count fixture was corrected before the passing full run. No real migration, UI/Android change, live message send or deployment occurred. Composer schema is valid with a strict exact-pin warning; audit found43 advisories in12 existing packages and no Sanctum advisory. P2-005 is the next release gate.

## IMPLEMENTED — P2-005 dependency remediation

Full isolated regression passed **356 tests / 3,117 assertions** (138.676902s). Focused real-mail rendering checks passed **3 tests / 68 assertions** (1.745481s). Composer locked audit reports zero advisories, abandoned packages or filtered findings; strict manifest validation and locked PHP platform checks pass. Package discovery and all 97 routes boot successfully. New-test Pint, PHP syntax and scoped whitespace checks pass.

Updated 13 compatible packages, including Laravel 12.70.0, without additions/removals; all reported locked advisories are cleared. Sanctum remains 4.3.3; its manifest constraint is ^4.3.3 and strict validation now has no exact-pin warning. Application behavior required no compatibility fix. Added real array-mail recovery tests, including trusted recipient/link rendering and malformed legacy recipient rejection. Preserved .env, public/build and all pre-existing source/tests. [Detailed resolution](DEPENDENCY_REMEDIATION.md).

## REQUIRES VERIFICATION — shared limits

Actual MySQL/schema/data inventory, SMTP, cPanel cron/queues, secure production cookie/proxy behavior, live browser/viewport/language/Android behavior and real concurrency remain unverified. These limits prevent a production-readiness claim; they do not erase the completed source/test contracts. Next is Phase 3 inventory/preflight, with actual ownership and backup proof required before data backfill.
