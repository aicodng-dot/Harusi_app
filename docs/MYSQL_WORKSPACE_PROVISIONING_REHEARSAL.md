# Synthetic MySQL automatic workspace ownership

## IMPLEMENTED — P4-008 (2026-10-23)

All **10 actual MySQL 8.4.11 cases / 13 independent service workers** passed, including 12 serial observations, three exact creator-row waits, interruption after each completed insert and three actual unsigned allocator cases. Eight fresh synthetic schemas applied the frozen 28 repository migrations and contained 32 tables. The complete P4-006 **1,240 tests / 22,336 assertions** source gate is retained by exact source/test preservation; no new backend suite run is claimed for this TEMP/docs-only task.

An active customer creates exactly one new workspace, one active owner membership and one private hashed receipt in one transaction. Unicode-trimmed matching retries return the same workspace with zero DML. Changed payloads conflict; same-named workspaces are never adopted, and keys are scoped to the creator. Renames survive replay. Disabled/demoted owners, disabled creators and collation-equivalent corrupt request hashes refuse without repair or resurrection. Existing accounts, global roles/platform flags, event assignments/history and legacy ledgers remain conserved. Explicit primary metadata and row reads succeed with an unavailable read PDO and unconfigured global default; no replica attempt occurs. Receipt JSON/debug privacy also holds.

| Case group | Actual engine evidence |
|---|---|
| Serial creation and replay | Twelve observations verify exact three-row setup, normalized retry, creator/key/name separation, rename/revocation/hash refusal and explicit-primary behavior. |
| Same creator and exact request | The contender waits on users PRIMARY 221. One call commits three inserts; the second returns the same workspace with zero DML. |
| Creator revocation in both orders | Revocation-first refuses creation without setup. Creation-first commits its setup, then deactivation commits; a later retry refuses without resurrecting authority. |
| Worker interruption at three stages | Verified owned workers are terminated after workspace, owner-membership or receipt insert. Thread disappearance, exact complete row rollback and successful exact-request retry are verified separately at each stage. |
| Actual unsigned allocator IDs at three stages | Separate table allocators produce raw 9223372036854775808. Workspace/member/receipt stages refuse invalid_prerequisites after the expected 1/2/3 inserts, roll back every new row and return no unsafe receipt. |

## IMPLEMENTED — isolation and conservation

A new ACL-restricted TEMP MySQL data directory used only loopback 43495, MySQLX disabled, generated scoped credentials and the read-only Oracle-signed binary. UUID/datadir/port/bind/version were verified before fixture writes. Three waits matched both worker connection IDs and the exact users/PRIMARY/221 WAITING RECORD. Full row snapshots and table definitions were compared with only AUTO_INCREMENT counters normalized; ID reuse or allocator restoration is not claimed. All owned workers/server are stopped, the port is free, safe unavailable error 2002 was confirmed, and the four generated plaintext credential files were removed. Harusi's configured database, XAMPP and other services/data were untouched.

TEMP tooling passed PHP formatting/syntax and PowerShell parse checks. The unchanged 98 route method/path pairs, safe 32-table read-only CLI behavior, all 393 captured source paths outside task docs, environment/app key, public/index.php, dependencies/builds and Git index were checked. Documentation links and whitespace passed. Test names, request keys, hashes and returned fixture IDs remain private TEMP evidence; no generated credentials or fixture payloads are added to source control.

Independent review corrected the verifier's own status queries to use the primary before running the unavailable-replica case. The application provisioner/schema code was unchanged. The first execution completed serial setup but the verifier compared an omitted AUTO_INCREMENT option differently from a present allocator option. Safe engine diagnostics confirmed that distinction. The verifier now removes only that table option; only the identified disposable serial fixture was recreated before rerunning. The second execution passed the serial/concurrency/interruption groups but stopped before the allocator service call because information_schema returned cached allocator statistics. A safe comparison with SHOW CREATE and a fresh-statistics session confirmed this verifier precondition issue. Only the disposable case schemas were recreated, and the complete rehearsal then ran with session-local fresh statistics. No application code changed.

## REQUIRES VERIFICATION — release boundaries

This is selected synthetic MySQL evidence, not a configured/customer database rollout or every possible concurrency schedule. The provisioner remains dormant with no HTTP/CLI/job application caller, public signup, customer account creation or event/resource writer. Future callers must derive creator identity from authenticated state and apply current action/parent authorization; request keys and creation receipts are not permission tokens. Legitimate legacy ownership/platform approval, real database/private-file restore/fencing and hosting/SMTP/browser/device remain unverified. Remaining scanner/guest/offline/file boundaries and customer event/signup exposure are still open. No application/test/migration/API/UI/default/model/credential/owner/grant/dependency/build/environment/app-key or real-data change or deployment occurred.

[Automatic creation](WORKSPACE_PROVISIONING.md), [plan](PROJECT_PLAN.md), [phase contract](phases/PHASE_04_AUTHORIZATION.md), [phase report](PHASE_04_REPORT.md), [database](DATABASE_CONVENTIONS.md).
