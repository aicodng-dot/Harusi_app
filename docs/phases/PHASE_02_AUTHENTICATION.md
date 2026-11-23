# Phase 2 — shared authentication foundation

## TARGET / PLANNED — execution contract

Objective: keep one users table/password and support email or Tanzanian phone plus password, reliable web sessions, email recovery and secure API tokens. Public registration stays closed until Phase10 tenant isolation gates. User's continuation instruction authorizes phased implementation without optional approval pauses. Read all four canonical docs before each task.

Execute in order; update status/evidence here after each. P2-001 is **COMPLETED** (227 tests / 1,768 assertions); P2-002 is **COMPLETED** (265 tests / 1,946 assertions); P2-003 is **COMPLETED** (305 tests / 2,174 assertions, 2026-10-14). P2-004 is COMPLETED (353 tests / 3,049 assertions,2026-10-16); P2-005 is COMPLETED (356 tests / 3,117 assertions, clean locked audit); Phase 2 repository gate is COMPLETE. The Phase0/1 gate passed 43 docs / 372 links with 230 non-doc hashes unchanged. No real-data migration may assume working backup or ownership mapping.

| Field | P2-001 — repair web session foundation |
|---|---|
| Goal/scope | Restore remember support and authorized redirects; preserve scanner HTTPS before login; add clearly labelled /admin/login and /scanner/login entry pages sharing the same identity/form |
| Dependencies | Phase0/1 audit, R02/R03/R17 reproductions |
| Files | additive users migration, User, AuthController, bootstrap middleware, web routes/login view, AuthRoleTest/ScannerInterfaceTest |
| Database | nullable remember_token; preserve existing auth rows/IDs/password hashes |
| API | Existing POST /login and scanner shapes preserved; additive GET /admin/login and /scanner/login |
| UI | Functional remember control; distinct Scan Guests and Platform Administration titles; visible safe expired-session flash; public demo credential panel removed; no role-switch widget or layout redesign |
| Security | Hide remember token, reject unsafe/other-role intended redirects; canonical HTTPS before auth |
| Acceptance | Remember login/cookie/logout works; unsafe/role-intended mismatch falls back; permitted intended destination retained; labelled entry pages share identity; baseline HTTPS redirect regression passes |
| Tests | Focused auth/scanner routes, persistent cookie replay/token hiding/logout rotation, malicious/cross-role intended URL, compatible valid URL and labelled aliases; full regression after integration |
| Docs | Auth, API, DB, risks, plan and phase report |
| Status | COMPLETED — 2026-10-12; full 227-test suite passed |

Execution resumed 2026-10-12. Planned concrete migration: `2026_10_12_000000_restore_remember_token_to_users_table.php`; focused tests in new `AuthSessionFoundationTest.php`, preserving existing modified test files. Real database rollout remains separate from isolated verification. Public demo details are removed from the form as part of login hardening; deployment must still handle existing seeded accounts and backup safety (R09).

| Field | P2-002 — email/phone shared account |
|---|---|
| Goal/scope | Canonical optional phone on legacy accounts, active state and preferred language; email-or-phone sessions |
| Dependencies | P2-001; existing PhoneNumberService inspected |
| Files | additive migration, User, LoginIdentifier, AuthController, active-account middleware/bootstrap/provider/limiter, login view, AdminUserController/form/list, offline package selection/builder, focused tests |
| Database | nullable unique canonical phone, active default for existing users, locale default sw for new account settings; no invented phone backfill |
| API | Web accepts identifier; preserve existing email request compatibility |
| UI | Email-or-phone label; current admin/scanner operation retained |
| Security | Fail closed inactive accounts/sessions and new offline package enrollment; canonical uniqueness; generic credential errors; normalized-identifier/IP throttle; historical unsynced result imports preserved |
| Acceptance | 0712…,255712…,+255712… map to one account; invalid/duplicate/inactive rejected; guest service reused without accidental legacy data rewrite |
| Tests | Phone variants, duplicate canonical forms, legacy email, inactive/deleted/changed-role sessions, account/IP throttle, optional admin updates and truthful disabled badges, wrong-role routes, inactive new offline package enrollment |
| Docs | Auth, phone/DB conventions, account state, risks and plan |
| Status | COMPLETED — 2026-10-12; full265-test suite, new-file Pint and temporary frontend build passed |

Concrete P2-002 choices: account field `phone_number` is nullable/unique and canonical +255; `is_active` defaults true; `preferred_language` defaults sw and accepts sw/en. No legacy phone values are invented or guest records rewritten. Existing email spelling/storage is preserved; canonical limiter keys normalize case only for throttling. A shared LoginIdentifier helper reuses PhoneNumberService. Active-account middleware reloads persisted account/role so stale sessions cannot retain suspension or role changes. Admin edits preserve omitted optional fields and must prevent disabling the signed-in admin's own account. Public registration remains closed.

| Field | P2-003 — email recovery |
|---|---|
| Goal/scope | Email or phone lookup sends Laravel reset link to registered email; generic response, no SMS/WhatsApp OTP |
| Dependencies | P2-002; Laravel password broker config; mail deployment remains separately unverified |
| Files | recovery controller/delivery job/views/routes, shared login session marker, session-validation middleware/bootstrap/provider/limiters, auth/reset tests |
| Database | reuse password_reset_tokens; no duplicate reset store |
| API | GET/POST /forgot-password and GET /reset-password/{token}, POST /reset-password; standard password.request/email/reset/update names |
| UI | forgot-password link and reset forms; support guidance for missing email |
| Security | Enumeration-safe response, throttles, token expiry/single use, password confirmation; revoke remembered/API access according to contract |
| Acceptance | Existing email/phone accepted; only registered email gets link; unknown/malformed input stays generic; reset works once and expires |
| Tests | Mail/notification fakes, phone mapping, unknown enumeration, expiry/replay, old password denial and throttling |
| Docs | Recovery/auth/API/security/tests and plan |
| Status | COMPLETED — 2026-10-14; 40 focused tests and full305-test regression, Pint/syntax/routes/temporary build passed |

P2-003 execution decisions (2026-10-12; resumed2026-10-13): reuse Laravel broker (60-minute expiry, 60-second issuance throttle), LoginIdentifier and registered email. Recovery/reset each use10/min limits independently by IP and canonical identifier. All recovery requests receive the same public response and schedule the same single-use Laravel defer delivery path; do not require permanent workers. Broker minimum timebox is retained; real SMTP/response-flushing/delivery durability must be verified on hosting, and no constant-time production claim is made. Refuse log mail transports and failover/roundrobin composites (including URL overrides/nesting/cycles) because their framework exception logging may expose reset links. Use a safe dedicated single transport and record only safe failure class/reason, never message/debug/trace. Build reset URLs from trusted APP_URL. Exclude reset token/password fields from flashed input and logs. Reset rotates remember credentials and checks/deletes the broker token transactionally; no automatic login.

Use Laravel AuthenticateSession password-HMAC checks after persisted-account refresh, seeding the marker at successful login. Genuine persisted browser sessions without a marker require reauthentication once; valid remember-cookie restoration is checked by Laravel. Existing test actingAs helpers are not production sessions. Document the one-time legacy-session effect rather than claiming silent universal revocation. P2-004 subsequently integrates token revocation inside the reset transaction. Downloaded offline packages and unsynced result history are preserved.

| Field | P2-004 — secure device tokens |
|---|---|
| Goal/scope | Versioned login/logout/me API for same account; no Android UI/offline rewrite |
| Dependencies | P2-003; inspect compatible supported Laravel token facility before dependency change |
| Files | token dependency/config/migration if justified, API controller/routes/middleware, contract tests |
| Database | hashed opaque tokens, user relation, device name, expiry/revocation |
| API | implemented /api/v1/auth/login, /logout, /me; exact shapes recorded below |
| UI | None |
| Security | HTTPS, active user/event authorization, normalized login throttle, expiry/revocation, no plaintext stored password/token |
| Acceptance | Same email/phone/password works; me/logout enforce expiry and active status; browser sessions/scanner intact |
| Tests | Authentication, hashed storage, expired/revoked/inactive tokens, role/event permissions, no CSRF confusion |
| Docs | Auth/API/device/security/DB/tests and plan |
| Status | COMPLETED — 2026-10-16; 353 tests / 3,049 assertions passed; dependency advisories tracked separately in P2-005 |


### P2-004 concrete execution decisions (2026-10-14)

Status: COMPLETED in source/tests on2026-10-16. Dependency choice: pin laravel/sanctum4.3.3; its PHP^8.2 and Illuminate12 requirements match this checkout. Composer dry run resolves one added package with no existing package updates/removals. Sources: [release manifest](https://github.com/laravel/sanctum/blob/v4.3.3/composer.json), [guard](https://github.com/laravel/sanctum/blob/v4.3.3/src/Guard.php), [provider](https://github.com/laravel/sanctum/blob/v4.3.3/src/SanctumServiceProvider.php). Do not run installer scaffolding, seeders or real database migrations.

- Add POST /api/v1/auth/login, GET /api/v1/auth/me and POST /api/v1/auth/logout. API routes are separate from web/session/CSRF middleware. Keep the default browser guard; Sanctum accepts bearer tokens only (guard=[], routes=false, users provider), with no SPA cookie fallback or extra CSRF-cookie route.
- All three routes require HTTPS, including local API access; insecure requests return JSON426 without redirect. All API success/errors use no-store/private and no-referrer headers; exceptions return JSON even without Accept. Web/scanner response shapes stay unchanged.
- Login accepts identifier (or email only when identifier is absent), password and device_name (required string, max100). Identifier is max255; malformed field types return422, bad/unknown/inactive/unsupported credentials return the same401 message. Ten attempts/minute apply independently per IP and canonical identity.
- Under the user's row lock, recheck current password and availability, then issue a random Sanctum token stored only as SHA-256 hash. Device name is metadata, never ownership proof. Token lifetime is fixed24hours, with an explicit expires_at and no refresh/sliding extension. Retain at most10 unexpired tokens per account; a successful new login prunes expired tokens and revokes oldest tokens beyond that bound. Return the plaintext token once, only in the login response body.
- Server-issued abilities are auth:read and auth:logout only, never wildcard or client-submitted role/event/abilities. Login201 returns {success:true,message:"Signed in.",data:{user:{id,name,email,phone_number,preferred_language,role,event_id,gate_name},access_token,token_type:"Bearer",expires_at}}. Me200 returns {success:true,message:"Authenticated.",data:{user:{same allowlist}}}. Logout204 deletes only the current persisted token, with no body. Errors use {success:false,message,errors:{}} (field arrays for422); rate limits use429 and Retry-After.
- Require a real persisted token and fresh eligible user on protected actions. Inactive/deleted/unsupported accounts fail401; scanners additionally need their explicitly assigned existing active event (login stays generic401; me fails403 when event permission becomes unavailable). Never select/create a default event from API input. Logout remains possible for a valid token when an event is unavailable. No scanner admission, workspace, registration or Android-client endpoints are introduced.
- Password recovery deletes all user tokens inside the existing reset transaction. Administrative password changes lock the same user row and revoke tokens in the same transaction; administrative availability/role/assignment changes also revoke tokens. Preserve omitted optional fields and existing validation/redirects. Direct SQL or future credential writers must use this revocation boundary; arbitrary external writes are not claimed to revoke tokens. Downloaded offline packages and pending imports stay intact.
- Add an additive standard personal_access_tokens table migration and User token relation. Use SQLite-memory/fake messaging for verification. Tests must cover hashed storage, canonical login, serialization, HTTPS, expiry, replay/revocation, bounds, abilities, cookie/bearer isolation, active role/event changes, reset/admin revocation and rollback. Real MySQL concurrent ordering, deployed HTTPS/proxy settings and Android secure storage/interoperability remain separate verification gates.

## P2-005 — locked dependency advisory remediation

Status: COMPLETED in source/tests on 2026-10-16. Discovered during P2-004 verification: the initial Composer audit reported 43 advisories in12 existing packages: guzzlehttp/guzzle(9), guzzlehttp/psr7(4), laravel/framework(4), league/commonmark(12), league/flysystem(1), symfony/http-foundation(2), symfony/http-kernel(1), symfony/mailer(1), symfony/mime(2), symfony/polyfill-intl-idn(1), symfony/routing(2), symfony/yaml(4). No Sanctum advisory was reported.

| Field | Contract |
|---|---|
| Objective/scope | Resolve reported locked runtime dependency advisories within supported PHP8.2/Laravel12-compatible releases; preserve current application contracts |
| Dependencies | P2-004 functional gate, captured Composer audit and package manifests |
| Files | Composer manifest/lock, focused real-mail rendering tests, necessary compatibility fixes only if regressions demonstrate need; evidence docs |
| Database/API/UI | No schema/data migration or new endpoint/UI; preserve existing contracts |
| Security | Compare advisory IDs before/after; no disabling advisory blocking, insecure transports or unsupported platform bypass |
| Acceptance | Review a compatible dry-run resolution; update only necessary package families/dependencies; clean locked audit, manifest validation and full regression; preserve .env/build/unrelated source |
| Verification | Composer dry-run/lock diff, audit and platform checks, full isolated PHPUnit, syntax/whitespace; record residual advisories or environment limits truthfully |
| Exclusions | No production deployment, real DB/seed/message sends, broad major framework/PHP upgrade or Android changes |

### P2-005 resolution review (2026-10-16)

The reviewed dry run updates the 12 advisory-bearing packages and the required guzzlehttp/promises dependency: 13 updates, no installs/removals. Laravel stays on 12 (12.70.0), Symfony on 7.4, and PHP on the installed 8.2.12. Use a targeted Composer update with --with-all-dependencies --minimal-changes; disable scripts/plugins during resolution and installation. The clean candidate audit is preliminary until the installed lock, platform checks and full isolated regression pass.

Change Sanctum's manifest constraint from exact 4.3.3 to ^4.3.3 to remove Composer's strict-validation warning; retain the reviewed 4.3.3 package in the lock. Do not loosen platform or security checks. Composer package discovery runs separately with isolated test configuration. Preserve application source, .env, public/build and configured database. Symfony HTTP Foundation 7.4.21 includes the follow-up fix for whitespace separators described in the [maintainer advisory](https://symfony.com/cve-2026-108082).

### P2-005 completed evidence

Full isolated regression passed **356 tests / 3,117 assertions** (138.676902s). Focused real-mail rendering checks passed **3 tests / 68 assertions** (1.745481s). Composer locked audit reports zero advisories, abandoned packages or filtered findings; strict manifest validation and locked PHP platform checks pass. Package discovery and all 97 routes boot successfully. New-test Pint, PHP syntax and scoped whitespace checks pass. The installed resolution matches the reviewed 13-package lock diff. No application compatibility edit was required; only the new regression test was added. All 294 baseline paths were compared; .env, public/build and pre-existing source/tests remain intact. [Package/advisory details](../DEPENDENCY_REMEDIATION.md). Phase 2 repository gate is complete.

## REQUIRES VERIFICATION — limits

Actual MySQL migrations, production SMTP and mobile token storage/device interoperability require their real environments. Do not open public registration, create workspace owners or treat server tests as Android verification in Phase2.
