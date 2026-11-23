# Roles and permissions

Audit date: 2026-10-11. Source inspection only unless the [test record](TEST_STRATEGY.md) says otherwise.

## CURRENT — account modes and workspace memberships

| Identity | Current capabilities | Boundary |
|---|---|---|
| Unauthenticated visitor | Shared login/recovery, device-token login and public WhatsApp webhook routes subject to their validation/throttles | Protected web routes require sessions; protected device auth calls require tokens |
| `admin` | Unowned legacy event catalogue/direct metadata controls, eligible unowned staff account management, selected-event guests/QR/reports/notifications, offline session/package/results workflows and settings | Membership not required for legacy events; no membership/platform bypass for owned metadata or protected staff targets |
| `scanner` | Assigned-event dashboard, QR validation, manual lookup/admission, recent scans | `users.event_id` plus controller checks; scanner role cannot access admin routes |
| `customer` | Read-only own active workspace membership home; shared web sign-in/recovery | Separate from staff/scanner and platform capability; no signup or customer resource writers |

Evidence: [routes](../routes/web.php), [role middleware](../app/Http/Middleware/EnsureUserHasRole.php), [User](../app/Models/User.php), [AdminUserController](../app/Http/Controllers/AdminUserController.php) and [ScannerController](../app/Http/Controllers/ScannerController.php).

Admins/scanners cannot enter each other's areas; [AuthRoleTest](../tests/Feature/AuthRoleTest.php) and [AdminUserManagementTest](../tests/Feature/AdminUserManagementTest.php) encode this. P2-002 refreshes persisted active state/role before web authorization; unavailable accounts are denied and role changes take effect. Global admin CRUD can assign either role, phone, state and stored language. Self-deletion and self-disable are blocked; self-demotion/last-admin protections remain incomplete. This CRUD must not be exposed unchanged to workspace owners.

[OfflineScannerSessionPolicy](../app/Policies/OfflineScannerSessionPolicy.php) is a real existing policy; it mostly requires `isAdmin()` and session lifecycle conditions. Offline controllers add selected-event checks through [HandlesOfflineScannerAdmin](../app/Http/Controllers/Admin/Concerns/HandlesOfflineScannerAdmin.php). There is no general tenant policy layer for events, guests, users or workspaces.

## IMPLEMENTED — dormant authority fields

P3-002 stores owner/manager membership separately, inactive by default, and prepares a hidden/non-fillable is_platform_admin boolean default false. No existing admin is promoted by the migration, and no membership changes users.role/event_id/gate_name. Legacy role=admin routes keep their existing global authority; the new platform flag is not yet enforced or granted by HTTP. Workspace relations include inactive memberships and are not authorization. Tests prove scanner owners remain scanner-only and protected inputs/profile fields cannot grant or expose the flag. Explicit platform grants/enforcement and tenant policies require later reviewed tasks before customer exposure.

## TARGET — user-mandated separation of authority

| Role/capability | Scope | Intended responsibility |
|---|---|---|
| Platform Admin | Platform | Customers, workspaces, platform settings, plans, subscriptions, shared template catalogue, support and system health |
| Owner | Workspace membership | Workspace/events, guests, invitations, passes, scanners, messages, reports, managers and future billing |
| Manager | Workspace membership | Explicitly permitted operational features; exact permission subset remains to be agreed |
| Scanner | Event assignment | Scan, manual lookup, admit and recent scans for assigned events |

Workspace membership and scanner assignment are separate concepts. A scanner does not inherit event-editing permission. A workspace owner does not become a platform administrator. Android and web do not create separate identity roles.

## PLANNED — authorization contract

For workspace actions, authorize authenticated account → account-active state → active membership → action permission → workspace/event ownership → resource ownership. For scanner actions, authorize the same identity and account state plus an active event assignment and allowed scanner action; scanner-only access must not require giving broad workspace-management permissions.

Evaluate authorization server-side for route-bound IDs, selected context, bulk imports/exports, files and generated cards. A context switch is navigation, not permission. Keep platform permissions distinct from workspace roles, with audited support access defined before adding any cross-tenant override.

Add negative tests for cross-workspace IDs, bulk mixtures, inactive memberships, revoked assignments, cross-event admissions, role injection, unauthorized user/password management and token abilities. Preserve current role regression tests until an approved task deliberately replaces their expected behavior.

## REQUIRES VERIFICATION — decisions

Decide the exact manager matrix, ownership transfer/last-owner rules, multi-role mode selection, platform support access and audit requirements, assignment lifecycle and revocation during an offline session. These details are not implemented or silently authorized by the role names above. No existing admin is automatically classified as a customer or platform operator without a reviewed legacy mapping.

## IMPLEMENTED — P3-003 source/test update (2026-10-18)

P3-003 observes eligibility for explicitly proposed owners (active supported admin/scanner) and platform targets (active legacy admin only). Legacy admin status does not automatically grant platform capability. Requested existing flags require review; unlisted flags are unchanged. Passing diagnostics establish no legitimate-owner or privilege approval. [Guide](OWNERSHIP_MAPPING_PREFLIGHT.md).

## IMPLEMENTED — P3-005 source/test update (2026-10-18)

The dormant P3-005 source service creates only inactive owner memberships in isolated fixtures and rejects nonempty platform proposals. It does not activate customer access, grant platform capability or alter scanner/global roles or assignments. Actual execution/authority enforcement remain separate. [Guide](WORKSPACE_BACKFILL_PREPARATION.md).

## IMPLEMENTED — P3-008 dormant authority context (2026-10-19)

AuthorityContextResolver performs fresh primary reads on an explicit connection and strict positive PHP integer user/optional workspace IDs. It independently observes canonical active-account eligibility, explicit platform capability and the exact requested active owner/manager membership. It has no runtime caller, action-permission API, default selection, grant, activation, policy wiring, write, transaction or lock. A scanner-owner may have workspace membership facts while retaining scanner role; platform flag and requested membership stay independent. Manager recognition supplies no action permission matrix. Observed facts are not authorization or an unforgeable token. Current admin/scanner account-role compatibility is preserved; Manager action permissions, platform support bypass, ownership transfer/last-owner rules and runtime tenant enforcement remain undefined. Visibility follows the explicit connection and caller transaction/isolation; fresh queries do not fence concurrent changes or invalidate an already retained context. Real ownership/platform approval, customer restore, membership activation, maintenance fencing and Phase4 action/resource policies remain open. Phase3 remains IN PROGRESS. [Scope and verification](AUTHORITY_CONTEXT.md).

## TARGET — automatic responsibility (2026-10-21)

The user directs self-service ownership: the authenticated creator of a new workspace becomes its owner and manages the workspace/events/team. Person, organization and planner are customer descriptions, not extra authority. Platform privileges remain separate. New setup requires no developer manifest; old unmapped events stay private until a legitimate ownership process establishes responsibility.

## IMPLEMENTED — P3-022 dormant creation

The new [provisioner](WORKSPACE_PROVISIONING.md) creates an active owner only for its freshly created workspace in one transaction. It never changes global role/platform flags, scanner assignments, old memberships or old events. Replays never reactivate revoked/demoted/missing ownership. Current eligible accounts remain active admin/scanner; no customer account/role or runtime tenant access is added. Legacy backfill memberships remain inactive. Action permissions, team/transfer/last-owner controls and customer identity enforcement still require source tasks before exposure.

## IMPLEMENTED — P4-001 five-action observations

The dormant [WorkspaceAccess reader](WORKSPACE_ACCESS.md) defines a selected operational manager subset: workspace view and event create/view/update. Owners also may rename their workspace. All other actions deny, including team/billing/transfer/deletion until separately implemented. Active membership and account are reread for every call; platform/global/scanner status alone grants no bypass. Event actions match the exact workspace parent. This is source preparation with no runtime caller; existing global admin access remains unchanged and must be scoped before customer exposure.

## IMPLEMENTED — P4-002 selected child-resource observations

Owners/managers passing fresh event view/update observations may pass the four exact guest/QR actions only when all requested parents match. Same-event QR/guest swaps deny. Global/platform/scanner roles and tokens/codes grant no bypass. Unknown bulk/create/delete/file actions deny; existing runtime remains unchanged. [Contract](GUEST_ACCESS.md).

## IMPLEMENTED — P4-003 neutral customer mode

The exact customer role supports web identity/recovery and its own read-only membership home, never legacy staff/scanner or device-token access. Raw customer ID range checks precede Eloquent scope identity. Legacy staff user index and bound CRUD now exclude customer/unsupported targets, including fresh locked-role checks and no platform bypass. Owner/manager membership remains independent: active customer creators can use the dormant new-workspace service, and explicit authority readers recognize them. No existing identity is converted or granted ownership. Legacy backfill eligibility is unchanged. Global event/file isolation and full team/transfer/scanner workflows remain open. [Contract](CUSTOMER_WEB_IDENTITY.md).

## IMPLEMENTED — P4-004 direct legacy event boundary

Workspace-owned events no longer appear in the legacy catalogue/layout or permit direct metadata controls, even for a platform-flagged admin who owns that workspace. This does not grant customer event management or close selected-event/offline/account/file boundaries. [Scope](LEGACY_EVENT_ADMINISTRATION.md).

## IMPLEMENTED — P4-006 legacy staff scope

An admin may manage only a canonical ordinary legacy admin/scanner identity with known-false platform flag, no membership row and a null or actual safe unowned assignment. Workspace-linked/platform/customer/unsupported targets are hidden and cannot be edited, reset or deleted through this surface. Actor platform/membership does not bypass this boundary. Inactive legacy accounts remain manageable. Scanner assignment requires a safe active unowned event; empty input only selects the sole eligible candidate. This adds no workspace team or platform account management permission. [Contract](LEGACY_STAFF_ACCOUNTS.md).

## IMPLEMENTED — P4-009 customer workspace setup (2026-10-23)

Existing authenticated customers now create an empty workspace through two protected HTML routes and automatically become its active owner. Exact customer role is rechecked under the creator lock before creation/replay; submitted ownership/global fields grant no authority. Matching retries add no rows and failed inserts roll back. Full isolated source gate: **1,275 tests / 22,874 assertions**. Event/guest writers, signup and complete tenant isolation remain closed; no configured data or deployment changed. [Setup contract and verification limits](CUSTOMER_WORKSPACE_SETUP.md).
