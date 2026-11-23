# Legacy staff account boundary

## IMPLEMENTED — P4-006 source and isolated tests (2026-10-22)

Legacy staff account management now excludes every workspace-linked or platform target, including inactive membership rows and scanner/admin identities assigned to owned events. Targets require a safe canonical positive PHP-range raw user ID, exact admin/scanner role, known-false raw platform flag, no membership of any state and either null assignment or an actual safe unowned event. The primary catalogue applies these predicates before pagination and constrains its eager event relationship to safe unowned parents. Direct read/write targets are refreshed before validation; an actor's platform flag or memberships never bypass the target boundary. Inactive ordinary legacy accounts remain manageable.

Event options use primary active/null-workspace/positive-range filters with byte-exact status. Nonblank scanner assignment must be a canonical positive PHP integer or decimal string reaching the controller and resolve to a fresh active unowned event. Shared HTTP trim/empty-string middleware still runs first. Missing/empty assignment selects only the sole eligible active legacy event; zero or multiple candidates produce the same generic event_id error. No controller defaultEvent call or event creation remains. Admin assignments/gates are cleared as before; role/customer/platform/member input cannot grant additional authority.

Store explicitly supplies the eligible scanner event and locks its parent before inserting the User, avoiding the unchanged model default hook. Update/delete capture the old assignment, lock deduplicated old/proposed Events in exact ascending integer order, then lock User. They recheck raw identity, exact role/false platform flag and unchanged old assignment, followed by a current locking membership-row read. Assignment drift denies instead of taking a newly discovered Event lock after User. Only then do account/credential/token writes occur in the same transaction. Existing password hashing, remember-token rotation on password reset, token revocation and rollback remain intact.

The assigned-event form now says “Choose an event” and explains its sole-event automatic choice. Routes and legacy unowned workflows are preserved. Workspace team management and platform account administration require their own future authorization; these identities are deliberately unavailable to the legacy staff screen.

## IMPLEMENTED — verification

Full isolated regression passed **1,240 tests / 22,336 assertions** (188.002481s), with zero errors/failures/skips. Focused staff/customer/auth/recovery/token/assignment/offline compatibility passed **298 tests / 3,308 assertions** (68.903706s), including 68 new cases / 601 assertions. The old catalogue first reproduced private-account exposure before the controller was installed. Scoped controller/new-test Pint and task PHP syntax passed. All 98 route method/path pairs and safe 32-table CLI inventory/inspection behavior remain unchanged.

Protected-target fixtures cover both workspaces and account/token/event/membership conservation. Controlled state changes exercise parent reassignment/status loss, role/platform/assignment/membership drift, transaction rollback and parent/User/membership query order. Primary-read traps and strict raw IDs verify the selected paths. These are fixture schedules, not simultaneous MySQL workers. Existing foundation coverage is deliberately strengthened: after its managed identity receives the protected platform flag, the legacy update now returns404 and preserves the complete raw row. All other existing tests remain unchanged.

## REQUIRES VERIFICATION — remaining work

This source task follows live offline package Event-before-User ordering and current compliant membership writers' User-before-membership protocol; it does not prove actual MySQL blocking or protect arbitrary external SQL/future writers. The dormant legacy backfill still requires its maintenance fence. Primary list/edit queries are point-in-time observations; the mutation locks cover these staff writes only. Integer PK/FK schemas prepared by repository migrations are assumed for SQL list filtering; raw bound identities are checked separately. SQLite cannot exercise MySQL unsigned overflow values or collation/locking behavior. Global model/scanner defaultEvent fallbacks, device/recovery code, scanner admission and remaining guest/offline/file lineage/write/audit/download/delete boundaries stay unchanged. Customer writers/signup remain closed. Actual configured database/schema, legitimate legacy ownership/restore/fencing, MySQL provisioning and hosting/SMTP/browser/device checks remain unverified. No real account/credential/owner/grant/data/configuration/app-key/service/dependency/build change or deployment occurred.

[Plan](PROJECT_PLAN.md), [phase contract](phases/PHASE_04_AUTHORIZATION.md), [report](PHASE_04_REPORT.md), [selected-event checks](LEGACY_SELECTED_EVENT.md), [customer identity](CUSTOMER_WEB_IDENTITY.md).

## IMPLEMENTED — selected engine follow-up

P4-007 adds four actual synthetic MySQL cases / nine independent workers with three exact primary-row waits, older caller snapshot/current membership refusal, compliant membership serialization and interrupted credential/token rollback/retry. Separate actual collation/unsigned-ID catalogue observations also passed. Application/test source is unchanged; original source-task limits remain historical and the follow-up is bounded to its named cases. [Evidence and remaining limits](MYSQL_STAFF_REHEARSAL.md).

## IMPLEMENTED — P4-011 locked legacy package generation (2026-10-23)

Package generation now locks the unowned Event, sorted eligible actor/scanner Users and current scanner memberships before snapshots or generation effects. Scanner choices/validation exclude workspace/platform identities; late parent/actor denial and private database-error feedback remain safe. Existing transaction/crypto/payload/compensation logic is retained. Full source gate: **1,372 tests / 23,870 assertions**. Actual MySQL schedules and postcommit/later offline/file boundaries remain separate. [Scope](LEGACY_OFFLINE_PACKAGE_GENERATION.md).

## VERIFIED — P4-012 package-generation engine checks (2026-10-23)

All **six actual MySQL 8.4.11 case groups / nine independent service workers** passed, including twelve serial observations and four exact row waits. Six fresh synthetic schemas each applied the frozen 28 repository migrations and contained 32 tables. The P4-011 **1,372 tests / 23,870 assertions** source gate is retained by exact application/test fingerprints; no new backend suite run is claimed for this TEMP/docs-only task.

Selected Event/User waits, a current membership read after a caller RR absence snapshot, committed production generation before transfer and real driver-failure row/file compensation passed. All configured data/services are preserved and the owned server/credentials are cleaned up. Postcommit and later offline/file boundaries remain separate. [Evidence and limits](MYSQL_PACKAGE_GENERATION_REHEARSAL.md).
