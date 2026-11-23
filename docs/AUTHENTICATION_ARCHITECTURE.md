# Authentication architecture

Audit date: 2026-10-11. **CURRENT** means inspected working-tree source; it does not certify production configuration. Test execution is recorded in [TEST_STRATEGY.md](TEST_STRATEGY.md).

## CURRENT — shared web identity

There is one [User model](../app/Models/User.php), one users table and a session-backed web guard in [config/auth.php](../config/auth.php). Current source includes name, unique email, hashed password, nullable unique canonical phone_number, is_active, stored preferred_language, admin/scanner/customer account mode, event ID and gate (the latter two remain scanner-related). Email-verification columns remain absent. Guest phones are separate from account identity and need not be unique. Prepared migrations have not been applied to the configured database.

[AuthController](../app/Http/Controllers/AuthController.php) accepts identifier (email or phone) plus password and preserves legacy email-only requests. Auth::attemptWhen requires active supported-role identity; successful login regenerates the session and logout invalidates it/regenerates CSRF. [Routes](../routes/web.php) expose GET/POST `/login`, labelled GET `/admin/login` and `/scanner/login`, and POST `/logout`; named login throttling permits10/min per IP and per canonical identifier. Protected areas require the matching role and refreshed persisted account state. Web forms use CSRF; an entry label never grants a role.

[The current login view](../resources/views/auth/login.blade.php) has a text email-or-phone field, password, Remember me, escaped session feedback and username/current-password autocomplete. It has **no Admin/Scanner switch**. P2-001 added entry labels and removed public demo details; seeded accounts still need deployment review. `/login` is common and makes no customer-portal promise. The customer home now uses the stored EN/SW language; the other existing screens are not fully translated.

## CURRENT — P2-001 source changes (2026-10-12)

An additive [remember-token migration](../database/migrations/2026_10_12_000000_restore_remember_token_to_users_table.php) restores Laravel's nullable 100-character column; User hides it from serialization. Successful login consumes the intended destination and accepts only a same-origin or root-relative URL in the account's authorized area. Scheme/host/effective port must match; userinfo, protocol-relative, controls, traversal and encoded path segments are rejected. Valid query strings remain. Fresh login clears selected-event/import-preview state; successful account replacement also clears old intended context. Failed replacement keeps the current identity. Login without remember expires a previous recaller cookie.

Scanner HTTPS middleware now has priority before authentication; it remains conditional on HTTPS app.url and retains localhost exemption. Session tests cover real recaller-cookie replay and logout rotation. **IMPLEMENTED:** P2-001 full isolated suite passed 227 tests / 1,768 assertions on 2026-10-12. Production schema rollout has not occurred.

At the historical P2-003 gate there was no token API; P2-004 now adds the separate Sanctum bearer foundation below. Registered-email recovery is implemented and verified in isolation; see [ACCOUNT_RECOVERY.md](ACCOUNT_RECOVERY.md).

## IMPLEMENTED — P2-002 shared identifier/account availability

Full isolated suite passed **265 tests / 1,946 assertions** on 2026-10-12; formatting/syntax/whitespace and temporary build passed. [LoginIdentifier](../app/Services/LoginIdentifier.php) resolves email without changing stored spelling or calls the existing PhoneNumberService. User writes canonical phone strings; DB uniqueness permits multiple null legacy phones. Language defaults sw and supports sw/en.

[EnsureActiveAccount](../app/Http/Middleware/EnsureActiveAccount.php) reloads persisted identity/role before authorization and rejects suspended/deleted/unsupported identities with session/cookie invalidation; JSON uses401 message, browser redirects to login. Admin optional edits preserve omitted fields and reject self-disable. New offline package selection/validation/builder require active scanners; already exported isolated authorization cannot be instantly revoked, and historical result imports remain recoverable.

The [account-field migration](../database/migrations/2026_10_12_010000_add_shared_identity_fields_to_users_table.php) is additive; no legacy phone backfill or guest rewrite occurred. Actual DB uniqueness/collation/data and deployment require separate verification.

## IMPLEMENTED — P2-003 recovery and password-session revocation

Email/phone recovery delivers only to the registered email through a generic deferred path and Laravel broker. Reset uses trusted APP_URL, CSRF, IP/identity throttles, expiring single-use tokens, explicit password hashing and transactional remember rotation/token consumption. Unsafe mail log/composite transports are refused; delivery errors are sanitized. Successful reset requires fresh login. Full isolated suite passed **305 tests / 2,174 assertions** on 2026-10-14.

Login seeds password_hash_web; AuthenticateWebSession checks Laravel's password HMAC after persisted-account refresh. Password changes revoke older sessions on next access, including administrative edits. Existing browser sessions without a marker require one-time reauthentication; valid remember restoration into a fresh session is supported. Old remember credentials fail after reset. P2-004 integrates transactional API token revocation; disconnected offline packages/history are preserved. Real SMTP, hosting response behavior, MySQL concurrency and production cookie/access-log controls remain unverified.

## IMPLEMENTED — P2-004 device bearer foundation

Sanctum4.3.3 uses a users-provider guard with cookie fallback disabled. POST /api/v1/auth/login, GET /me and POST /logout require HTTPS; no SPA/CSRF-cookie route is registered. Issuance locks and reloads the user before checking shared credentials and active supported-role account state. Scanner login additionally requires its persisted active event assignment; admins do not require an assigned event. Tokens are hashed, expire after24hours, have only auth:read/auth:logout abilities and are bounded to10 per account. Protected calls reload persisted active account state. Scanner me requires the persisted active event assignment; logout accepts a valid current token after assignment loss or event unavailability. No default event is created.

Recovery and administrative password edits revoke all account tokens transactionally using the same user-row lock as issuance. Admin availability, role, event and gate changes also revoke tokens; ordinary profile edits preserve them. Logout deletes the current token only. Future/direct credential writers must use this boundary; arbitrary SQL writes are not automatically claimed to revoke bearer tokens. Browser/remember and API authentication remain separate. Full isolated regression passed **353 tests / 3,049 assertions** (124.386315s), including **48 tests / 875 assertions** in DeviceTokenAuthenticationTest. New-file Pint, 15 PHP syntax checks and scoped whitespace checks passed. Runtime registers97routes: the prior94 plus the three versioned auth endpoints; no Sanctum CSRF-cookie route is enabled. Tests use SQLite memory, fake notifications/HTTP and temporary Laravel storage. Existing public/build, .env and unrelated checked source are preserved.

## CURRENT — audit-baseline defects and remaining authorization gaps

| Finding | Evidence and practical consequence |
|---|---|
| Remember me/schema mismatch (audit baseline) | Earlier migrations omit/drop the column. P2-001 restores it additively; isolated tests verify persistence/replay/rotation. Actual configured database migration remains unverified. |
| Wrong-area intended redirect (audit baseline) | P2-001 replaces unconditional intended redirect with the authorized resolver above. Existing role middleware remains the permission boundary. |
| Authenticated POST login | Deliberate account replacement remains possible. P2-001 clears selected event, guest import and old intent only after successful replacement; failed credentials preserve the current account. |
| Roles are mutually exclusive | Admins cannot access scanner pages; scanners cannot access admin pages. Existing [AuthRoleTest](../tests/Feature/AuthRoleTest.php) explicitly expects both restrictions. A login label cannot grant an additional mode. |
| Account suspension | P2-002 adds persisted active-state checks on login/web sessions and new offline package eligibility. P2-003 adds password-session revocation; P2-004 adds expiring bearer tokens and transactional credential revocation; offline limits remain explicit. |
| Demo account deployment risk | P2-001 removes the public credential panel. [DatabaseSeeder](../database/seeders/DatabaseSeeder.php) still creates demo accounts; whether they exist in a deployed database remains unverified. |

Audit verification: the parent audit ran two temporary isolated SQLite probes (2 tests, 5 assertions). They reproduced a `QueryException` for `remember_token` on Remember me login and the scanner → intended admin URL → 403 path. These successful diagnostic assertions confirm the defects on the migrated test schema; they do not certify production behavior. The local MySQL connection probe failed with error 2002.

## TARGET — user-mandated shared authentication

- One user account and password across the customer website, scanner website and Android; use secure Laravel sessions for browsers and secure API tokens for Android.
- Email **or** normalized Tanzanian phone plus password. Adapt the existing [PhoneNumberService](../app/Services/PhoneNumberService.php), which already canonicalizes guest/notification phones to `+255…`; do not introduce a second normalizer or infer missing digits.
- Clear entry points for Manage My Event, Scan Guests and Platform Administration. Redirect only to an authorized destination and offer context switching only where the same account has multiple legitimate capabilities.
- Registered-email password recovery, no SMS/WhatsApp OTP and no paid per-login service.
- Registration eventually collects name, email, phone, confirmed password and preferred language, defaulting to Kiswahili. Public registration stays closed until tenant isolation is verified.

## REQUIRES VERIFICATION — deployment after Phase 2 source/test completion

1. P2-003 source/test gate is complete; actual mail/hosting/concurrency verification remains required before deployment.
2. P2-004 token foundation and P2-005 dependency remediation are complete in source/tests; the Phase 2 repository gate passes. Actual migration/mail/hosting verification remains required. Full Android scanner endpoints remain Phase14.
3. Before actual rollout, inventory schema/data/collisions, rehearse additive migrations and verify SMTP/HTTPS/cookies. Current legacy phones remain null; no guessed backfill.

P2-001 sessions/entry routes, P2-002 identifier/account state and P2-003 recovery/session revocation plus P2-004 device tokens are completed in source/tests. Public registration/customer/workspace authorization is not part of those tasks.

Every item needs its own task contract in [PROJECT_PLAN.md](PROJECT_PLAN.md), appropriate tests and documentation before completion.

## REQUIRES VERIFICATION — implementation decisions and environment

Sanctum4.3.3,24-hour tokens and a ten-token account bound are implemented. Production session/Remember me retention, legacy phone onboarding, email normalization/collation, account suspension semantics and treatment of users with several modes require decisions. A phone number's format does not prove its ownership; phone reuse and account-identity uniqueness need an explicit policy.

Production session driver, secure-cookie flag, HTTPS/proxy behavior, mail transport and deployed schema were not inspected through secrets or a production connection. [Session config](../config/session.php) defaults to database sessions, HTTP-only cookies and SameSite Lax; Secure is environment-dependent. Do not describe those defaults as verified deployment settings.

## IMPLEMENTED — P3-002 storage compatibility

P3-002 prepares workspaces(id/name/timestamps), workspace_users(required restrictive user/workspace links, owner/manager role, inactive default and unique pair), nullable indexed events.workspace_id and users.is_platform_admin default false. New relationships add no authorization or automatic ownership. Platform capability is hidden/non-fillable and excluded from existing HTTP/API inputs; legacy global admin/scanner roles remain unchanged. At the P3-002 gate the expected inventory covered 29 tables and the new columns; the inventory reached 31 after the ownership-ledger additions and now covers 32 including the P3-022 provisioning receipt. No default workspace, membership, event owner or platform grant is inserted. Shared credential/session/remember/token behavior remains compatible in the full 410-test regression. Membership activation, workspace permission evaluation and platform capability enforcement are separate from this additive storage task.

## IMPLEMENTED — P3-018 selected recovery/token MySQL checks (2026-10-21)

All **four selected actual MySQL 8.4.11 cases / eight independent PHP HTTP-kernel workers** passed across four fresh synthetic schemas (27 repository migrations / 31 tables each). Three pairs observed actual owned-thread users RECORD lock waits and complete precommit row invisibility. Reset/reset ended at 302 success / 422 generic replay refusal; reset-before-old-password login ended at 302 / 401; old-password login-before-reset ended at 201 / 302, with its newly issued token then removed by the reset. Accepted calls executed exactly three DML statements; refused contenders executed zero.

Each completed reset replaced only the target account password/remember token/updated_at, accepted the new password and rejected the old, consumed the target reset row and removed all target device tokens. The unrelated account, its reset/device rows and every other row across all 31 tables matched the baseline exactly, including guest manual code, QR token, counters and history. SHOW CREATE definitions matched with allocator counter values excluded; no AUTO_INCREMENT restoration is claimed.

These are selected synthetic transaction schedules on the recorded MySQL build, not exhaustive concurrency, power-loss durability or another hosting environment. HTTP kernel calls used APP_ENV=testing (CSRF bypass) and array sessions/cache, so deployed browser/session/rate-limit behavior and SMTP/device delivery remain unverified. PasswordReset events dispatch synchronously inside the transaction: database rollback cannot undo arbitrary external listener effects; no such app listener was found and no external-effect guarantee is claimed. A token issued by an earlier valid login can subsequently be revoked; already-running requests are not retroactively cancelled. No configured/customer database, XAMPP/other service, account/ownership/grant, API/UI/Android/dependency change or deployment occurred. Real ownership/platform approval, customer database/private-file restoration and maintenance fencing remain open; Phase 3 remains IN PROGRESS. [Guide](MYSQL_RECOVERY_REHEARSAL.md).

## IMPLEMENTED — P4-003 customer identity boundary (2026-10-22)

A distinct customer account can sign in by existing email/phone/password, remember its session, recover through its registered email and reach only its protected read-only workspace home. Raw customer identity IDs must be canonical positive integers within PHP range before Eloquent casting; new customer role never becomes admin/scanner/platform. Primary web account refresh precedes role checks. The independent EN/SW home selects only its own active owner/manager memberships, with fresh account predicates, real workspace parents, fixed 15-row pagination, escaped names and private no-store/no-referrer headers. It neither composes the admin layout nor queries events/guests/files. Legacy staff index and bound read/update/delete now exclude customer/unsupported identities, with fresh primary reads before validation and role predicates repeated on locked write targets. Legacy staff forms cannot create/promote a customer. New customer recovery eligibility is consistent in deferred send and reset; device token eligibility remains active admin/scanner only. Dormant authority/provisioning can accept active customer creators for new workspaces; legacy backfill eligibility is unchanged.

Full isolated regression passed **1,071 tests / 20,461 assertions** (170.769364s), with zero errors/failures/skips. Focused identity/home/auth/recovery/token/ownership regression passed **378 tests / 9,730 assertions** (55.085574s). The 60 new cases comprise 41 identity cases / 439 assertions and 19 home/admin-boundary cases / 286 assertions; both classes passed in the full run. Two cached raw-ID alias regressions first reproduced an incorrect HTTP 200 (expected 401), then passed with zero account lookups/writes and exact fixture/remember-token preservation after the raw-ID check was moved before the primary reread. Task PHP Pint/syntax, all 98 runtime route method/path pairs and the unchanged 32-table safe read-only CLI smoke passed.

This adds no signup, customer-account creation, workspace/event/guest/file writer, membership activation/grant or real-data migration. No configured account, ownership, password, token, environment/app key, service, dependency or existing build was changed. Existing legacy event/switcher/report/file/static-QR surfaces remain global and need isolation before customer event/writer exposure. Full customer portal/onboarding, complete action/resource policies, actual MySQL provisioning DDL/contention, actual database/private-file restore/legacy mapping/fencing, deployed SMTP/hosting and real browser/device/bilingual layout validation remain open. HTTP/HTML/DOM/source checks are not visual/device proof. Denied bearer requests may update only Sanctum's existing last_used_at/updated_at telemetry before middleware rejects; token identity/hash/abilities/expiry remain unchanged and no customer token is issued. Controlled target-role transition tests are fixture schedules, not concurrent-engine proof. Existing device middleware still uses default-read account refresh; primary-only web tests do not establish replica-safe bearer revocation. [Reference](CUSTOMER_WEB_IDENTITY.md).

## IMPLEMENTED — P4-006 credential management boundary

Legacy staff account management now excludes every workspace-linked or platform target, including inactive membership rows and scanner/admin identities assigned to owned events. Targets require a safe canonical positive PHP-range raw user ID, exact admin/scanner role, known-false raw platform flag, no membership of any state and either null assignment or an actual safe unowned event. The primary catalogue applies these predicates before pagination and constrains its eager event relationship to safe unowned parents. Direct read/write targets are refreshed before validation; an actor's platform flag or memberships never bypass the target boundary. Inactive ordinary legacy accounts remain manageable.

Event options use primary active/null-workspace/positive-range filters with byte-exact status. Nonblank scanner assignment must be a canonical positive PHP integer or decimal string reaching the controller and resolve to a fresh active unowned event. Shared HTTP trim/empty-string middleware still runs first. Missing/empty assignment selects only the sole eligible active legacy event; zero or multiple candidates produce the same generic event_id error. No controller defaultEvent call or event creation remains. Admin assignments/gates are cleared as before; role/customer/platform/member input cannot grant additional authority.

Store explicitly supplies the eligible scanner event and locks its parent before inserting the User, avoiding the unchanged model default hook. Update/delete capture the old assignment, lock deduplicated old/proposed Events in exact ascending integer order, then lock User. They recheck raw identity, exact role/false platform flag and unchanged old assignment, followed by a current locking membership-row read. Assignment drift denies instead of taking a newly discovered Event lock after User. Only then do account/credential/token writes occur in the same transaction. Existing password hashing, remember-token rotation on password reset, token revocation and rollback remain intact.

Recovery/device eligibility and their existing locking behavior are unchanged. No real credentials/accounts are touched. 1,240 tests / 22,336 assertions passed in isolation; actual engine contention and remaining rollout gates stay open. [Reference](LEGACY_STAFF_ACCOUNTS.md).

## IMPLEMENTED — P4-007 selected staff credential schedules

Three exact Event/User waits, current locking membership refusal inside an older caller snapshot and interrupted User/token rollback/retry passed on a separate synthetic MySQL server. Successful staff changes preserve unrelated credentials/history/reset rows while rotating the target password/remember token and deleting its device tokens. No real credentials or API/auth/recovery implementation changed. This is selected engine evidence, not all schedules or deployed CSRF/browser proof. [Reference](MYSQL_STAFF_REHEARSAL.md).
