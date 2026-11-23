# Legacy offline resource parents

## IMPLEMENTED — P4-010 (2026-10-23)

A shared primary resolver now joins each offline session/package/result-import/conflict head to its exact selected unowned Event. Raw positive PHP-range IDs and every relevant returned parent identity are checked; malformed, owned, foreign, missing and inconsistent chains return no model. Imports/conflicts must agree with their session Event, and a linked conflict log must belong to the same import/session; a null log is valid. Internal join aliases are removed from the returned model. Bound cached attributes/relations cannot supply permission.

All 15 bound controller actions resolve a fresh matching head before resource policies, relationship payloads, validation or service/file/audit work. Preview validates the submitted session's shape, resolves its parent and then validates/reads the file. Generated-package downloads validate the raw package reference and require the package's actual session to match before form validation. Existing allowed lifecycle policies, successful flows and 100 routes are retained. Denied requests use generic 404 and preserve selected/import context with no observed DML, audit or invented-file change.

The resolver returns current model data only after the joined parent checks. It contains no actor grant, default selection, ownership inference, transaction, row lock, mutation, repair, file access or retained permission. Controllers continue to require the existing admin session/role and lifecycle policies. An unknown old owner remains private, including when a legacy actor has a platform flag. No customer-facing offline capability is added.

## IMPLEMENTED — verification

The complete isolated backend suite passed **1,324 tests / 23,668 assertions** (308.478168s), with zero errors/failures/skips. Focused offline/selected-event checks passed **155 tests / 1,723 assertions** (24.357512s), including 49 new boundary cases. Seven scoped PHP syntax checks and final read-only Pint passed. All 100 route method/path pairs and the 32-table safe CLI remain unchanged. All 398 captured task-start paths outside declared source/docs, environment/app key, public/index.php, customer setup, dependencies/builds and Git index were preserved.

Both legacy/platform actors first reproduced private preview 422 before file validation; the retained old-code run records 2 tests / 2 failures / 0 errors, then the fixed cases pass. New checks cover all 15 bound actions, foreign unowned preview, mismatched import Event, four controlled parent changes after the earlier gateway, generated-package session mismatch, active/archived/cancelled history,14 malformed ID shapes before any query, eight broken parent/log links and optional null logs. Complete synthetic table/fake-file snapshots protect denials. A separate SQLite memory clone at transaction level 0 proves an ordinary read hits the failing replica control once, while all resolver reads make zero replica attempts and preserve source fixtures. Early test-only missing imports/active-session uniqueness issues were corrected; the package-ordering regression also moved the secondary package check before form validation.

## REQUIRES VERIFICATION — remaining boundaries

These are request-entry observations, not an authorization token, an atomic revocation/reparenting fence or a database rollout. Route model binding can already fetch a head before the controller and is not certified as replica-independent. Existing caller transactions/snapshots still govern visibility; primary reads do not override isolation. Related guest/scanner payload references, downstream Event/User/session/import/conflict lock order, pretransaction audit effects, filesystem download/archive/delete races and public/static files remain separate boundaries. Existing global actor eligibility is still checked by middleware/policies; this task does not add a locked actor refresh. No lifecycle policy, service transaction/storage/audit implementation, schema, migration, scanner admission, customer setup/event/signup writer, configured data/credential/environment/app key, service/dependency/build or deployment changed. SQLite controlled schedules do not prove MySQL concurrency. Browser/device/hosting/SMTP and actual database/ownership/restore/fencing remain unverified. Phase4 remains IN PROGRESS.

[Plan](PROJECT_PLAN.md), [phase contract](phases/PHASE_04_AUTHORIZATION.md), [phase report](PHASE_04_REPORT.md), [selected context](LEGACY_SELECTED_EVENT.md), [offline workflow](OFFLINE_SYNC.md), [API compatibility](API_CONTRACT.md).

## IMPLEMENTED — P4-011 locked legacy package generation (2026-10-23)

Package generation now locks the unowned Event, sorted eligible actor/scanner Users and current scanner memberships before snapshots or generation effects. Scanner choices/validation exclude workspace/platform identities; late parent/actor denial and private database-error feedback remain safe. Existing transaction/crypto/payload/compensation logic is retained. Full source gate: **1,372 tests / 23,870 assertions**. Actual MySQL schedules and postcommit/later offline/file boundaries remain separate. [Scope](LEGACY_OFFLINE_PACKAGE_GENERATION.md).

## VERIFIED — P4-012 package-generation engine checks (2026-10-23)

All **six actual MySQL 8.4.11 case groups / nine independent service workers** passed, including twelve serial observations and four exact row waits. Six fresh synthetic schemas each applied the frozen 28 repository migrations and contained 32 tables. The P4-011 **1,372 tests / 23,870 assertions** source gate is retained by exact application/test fingerprints; no new backend suite run is claimed for this TEMP/docs-only task.

Selected Event/User waits, a current membership read after a caller RR absence snapshot, committed production generation before transfer and real driver-failure row/file compensation passed. All configured data/services are preserved and the owned server/credentials are cleaned up. Postcommit and later offline/file boundaries remain separate. [Evidence and limits](MYSQL_PACKAGE_GENERATION_REHEARSAL.md).

## IMPLEMENTED — P4-013 locked session cancel/close (2026-10-23)

Existing administrator cancel/close effects now recheck the current unowned Event, eligible actor, exact Session parent and lifecycle conditions under the transaction locks. Immediate import parents and current imported/open-conflict state are checked before effects. Audit-write failures roll back and return generic feedback; internal generation/import transitions remain unchanged. Full source gate: **1,482 tests / 24,782 assertions**. Actual MySQL action schedules and other offline/file/postcommit paths remain separate. [Scope](LEGACY_OFFLINE_SESSION_ACTIONS.md).

## IMPLEMENTED — P4-014 locked package delivery (2026-10-23)

All three existing download paths now lock current unowned Event/actor/Session/Package rows through fully buffered file reading, metadata/audit and transaction completion. Current-export downloads recheck the pointer; historical downloads retain compatibility. Precommit delivery failures roll back delivery metadata/audits while preserving already-committed generation. Full source gate: **1,565 tests / 25,642 assertions**. File deletion, integrity, client receipt, commit uncertainty and actual delivery engine schedules remain separate limits. [Scope](LEGACY_OFFLINE_PACKAGE_DELIVERY.md).

## IMPLEMENTED — P4-015 archive metadata (2026-10-24)

Package/ResultImport archive metadata and audit now share current Event/actor/Session/resource checks and a transaction. The locked current parent is supplied to existing helpers; no file bytes are accessed or changed. Existing archive eligibility and history compatibility remain. Full source gate: **1,653 tests / 26,523 assertions**. Secure deletion, other offline/file actions and actual archive engine schedules remain separate. [Scope](LEGACY_OFFLINE_FILE_ARCHIVES.md).
