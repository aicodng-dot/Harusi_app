# Workspace and event access

## IMPLEMENTED — P4-001 dormant permission observations (2026-10-21)

[WorkspaceAccess](../app/Services/Tenancy/WorkspaceAccess.php) provides allows(Connection,userId,workspaceId,action,eventId=null):bool for future self-service callers. Every call rereads the explicit primary account, workspace and active owner/manager membership through [AuthorityContextResolver](../app/Services/Tenancy/AuthorityContextResolver.php). Global admin/scanner roles and an explicit platform flag grant no workspace bypass. It accepts no caller-supplied authority context, provisioning receipt or default/session workspace.

| Exact action | Active owner | Active manager | Event ID |
|---|---|---|---|
| workspace.view | Allow | Allow | Must be absent |
| workspace.rename | Allow | Deny | Must be absent |
| event.create | Allow | Allow | Must be absent |
| event.view | Allow if parent matches | Allow if parent matches | Required |
| event.update | Allow if parent matches | Allow if parent matches | Required |

Unknown actions deny even for owners. Team/billing/transfer/deletion, scanner admissions/assignments and guest/file/report/message/offline actions are unsupported pending separate resource contracts. The manager subset above is the chosen operational default; a customer persona such as person, organization or planner grants no extra privilege.

User/workspace and required event IDs must be positive PHP integers; numeric strings/floats/bools/null defaults are rejected. Exact action and resource shape are validated before connecting. Current supported eligible account roles remain active admin/scanner for compatibility; public customers must not be created with these global roles just to pass this reader. A cohesive neutral customer identity flow is a later task.

Event view/update additionally requires a safe integer single-column event ID primary-key schema and a bounded primary read matching the exact raw event ID AND workspace parent. Null/unowned, foreign, missing or malformed parents deny. No Eloquent/defaultEvent helper runs. Unavailable/unsupported/malformed state and raw errors deny without private logs or exception context. There are no writes, row locks or transaction changes. The same retained service makes fresh reads on each call; its booleans are point-in-time observations only.

## IMPLEMENTED — verification

The complete isolated backend suite passed **976 tests / 17,366 assertions** (89.569317s), with zero errors/failures/skips. Focused access/authority/provisioning checks passed **152 tests / 5,286 assertions** (3.592400s), including 38 new access cases. New-file Pint and both PHP syntax checks passed; all97 registered method/path pairs and the 32-table read-only CLI smoke remain compatible.

Checks cover owner/manager matrix, two-workspace/event mismatches, global/platform/scanner negatives, account/member changes, missing/unowned/changed event parents, strict pre-connect inputs, schema/error denials, primary/read/default traps and complete row/schema conservation. The caller transaction stays unchanged; actual concurrent authorization schedules and hosted-engine behavior are not established by in-memory tests.

## REQUIRES VERIFICATION — runtime exposure

This service has no runtime caller and does not protect the existing global-admin routes, child-resource/files/import/export/report/offline surfaces or public/static QR assets. It adds no customer account, registration, portal, route/controller/middleware/policy wiring, schema, write, grant or deployment. Future writes need current locked account/member/resource checks in the same transaction as the mutation; a retained boolean is never a revocation fence. Caller isolation/snapshots remain in effect and the separate reads are not an atomic authority snapshot. Event lifecycle/field validation is separate. Phase3 actual configured database/legacy ownership/restore/fencing gates remain open; synthetic MySQL provisioning checks and safe neutral customer identity are also required before runtime exposure.

New workspace setup will automatically make its authenticated creator responsible without a developer file. Unknown legacy event owners remain private. This access foundation is a step toward that flow, not a delivered customer screen. [Creation](WORKSPACE_PROVISIONING.md), [contract](phases/PHASE_04_AUTHORIZATION.md), [phase report](PHASE_04_REPORT.md), [plan](PROJECT_PLAN.md).

## IMPLEMENTED — P4-002 selected guest/QR follow-up

The separate [GuestAccess reader](GUEST_ACCESS.md) composes event.view/update observations with explicit child lineage for four exact guest/QR actions. WorkspaceAccess itself and its five-action matrix remain unchanged. Neither reader is wired into runtime routes/files or mutation transactions.
