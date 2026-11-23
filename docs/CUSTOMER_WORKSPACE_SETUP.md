# Customer workspace setup

## IMPLEMENTED — P4-009 (2026-10-23)

An existing active customer can open the independent EN/SW setup form and create an empty workspace. The account's authenticated safe identity is the creator; submitted user/owner/workspace/event/role/platform fields supply no authority. The customer-only service entry rereads exact customer role and active state under the same creator row lock used by the three atomic workspace/owner/receipt inserts. Matching normalized requests return the existing setup with zero DML; the account remains a customer and receives no global/scanner/platform capability. Same-name legacy workspaces/events are never adopted.

GET /customer/workspaces/create is customer.workspaces.create; POST /customer/workspaces is customer.workspaces.store. Both use existing web session, primary persisted-account refresh and exact customer role. POST retains web CSRF and the named customer-workspace-create limiter. These are HTML form routes even with an Accept:application/json header; framework auth/CSRF retain their existing JSON401/419 handling. No new bearer or versioned API is provided.

| Outcome | Current browser behavior |
|---|---|
| Form GET |200 independent localized HTML; fresh hidden lowercase UUIDv4 key; no setup writes |
| New setup / matching retry |302 to customer.home; generic localized created/already-created status; exactly 3 inserts / zero DML |
| Invalid input |422 HTML with escaped field errors; valid retry key retained, malformed key replaced |
| Changed payload / revoked owner receipt |409 HTML with review guidance and a fresh key; no repair or resurrection |
| Unavailable schema/database |503 HTML; same valid key and name retained for retry; no SQL/error/bindings exposed |
| Creator changed after gateway |403 generic response after locked account recheck; no setup mutation |
| Submission limit |429 localized generic text with Retry-After; 5/min per authenticated account and 20/min per IP |

The home remains an own-membership read; its only additions are a setup link and an allowlisted generic completion status. Setup uses a separate document and no admin composer or event/guest/file read. The name is Unicode-trimmed and must contain1–255 code points without control/format characters. Existing shared web input trimming runs first. Only the name and hidden request key are accepted as creation input. Keys are replay identifiers, not permission tokens, and never appear in URLs or diagnostic messages. No raw receipt/hash/identity is returned to the form.

The generic internal provision method retains its existing active customer/admin/scanner eligibility. The new provisionCustomer entry adds exact customer eligibility under the locked User read before both replay and creation; it does not nest a transaction or bypass metadata/primary/raw-ID checks. No existing account is created, renamed, promoted or assigned an event. A failed request rolls back every setup insert. As previously recorded, allocator counters need not be restored.

## IMPLEMENTED — verification

The complete isolated backend suite passed **1,275 tests / 22,874 assertions** (223.641685s), with zero errors/failures/skips. Focused customer identity/home/provisioning checks passed **151 tests / 1,990 assertions** (82.372603s), including 35 new setup cases. Scoped PHP formatting and eight syntax checks passed. Runtime registration contains exactly 100 routes: the preserved 98 plus two customer setup method/path pairs; the safe inventory still catalogs 32 tables. Environment/app key, Git index, public/index.php, unrelated source, dependencies and existing builds remain preserved.

Checks exercise escaped localized form/owner guidance/labels/CSRF, exact route middleware, injected ownership/global fields, normalized replay and private receipt hashes, changed/revoked owner conflicts, 13 malformed input cases, staff/unsupported/guest/disabled/unsafe identities, three controlled post-gateway role/active transitions, rollback after each insert and successful retry, invalid prerequisites, unavailable read PDO, enforced CSRF/session expiry and both limiter buckets with a fixed test clock. Full row snapshots preserve foreign workspaces/events/guests/QR data, credentials, recovery tokens and device tokens. Mail/notifications/HTTP are fake and no actual delivery occurs.

## REQUIRES VERIFICATION — release boundaries

This source task exposes only empty-workspace creation for existing authenticated customers. Public signup/account creation and customer event/guest/team/file/billing writers remain closed. No configured database/schema/migration, real ownership/grant/data/credential, environment/app-key/service/dependency/build or deployment change occurred. Tests use SQLite memory clones outside ambient transactions and TEMP storage, array cache/session/mail, sync queue and null WhatsApp. Controlled transitions are deterministic fixtures, not actual concurrent HTTP MySQL schedules. P4-008 separately verifies selected shared engine transaction behavior; the new HTTP customer-role wrapper is not a fresh engine rehearsal. Browser/device/responsive visual acceptance, configured database/private-file restore, legitimate legacy ownership/platform approvals, maintenance fencing and hosting/SMTP remain unverified. Phase4 remains IN PROGRESS.

[Plan](PROJECT_PLAN.md), [phase contract](phases/PHASE_04_AUTHORIZATION.md), [identity](CUSTOMER_WEB_IDENTITY.md), [automatic transaction](WORKSPACE_PROVISIONING.md), [engine evidence](MYSQL_WORKSPACE_PROVISIONING_REHEARSAL.md), [API compatibility](API_CONTRACT.md).
