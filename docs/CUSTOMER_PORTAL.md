# Customer portal

Approved target (2026-10-30): event/list/dashboard/guest/team/settings screens use the mapped PangaEvents PNGs in the [39-screen matrix](REFERENCE_UI_SCREEN_MATRIX.md). Existing legacy admin views are analogs, not customer authorization. Current own-workspace home/setup remains separate; customer events, invitations, message controls and billing stay gated by existing phases. No UI route is opened by this audit.

Current source review: 2026-10-25. A minimal own-workspace home and bounded empty-workspace creation are implemented; the full event portal below remains planned.

## CURRENT

There are three account modes: admin, scanner and customer. The customer has an independent own-membership home and P4-009 empty-workspace setup. [Routes](../routes/web.php) protect admin features by the admin role and scanner features by the scanner role. [Admin layout](../resources/views/layouts/admin.blade.php) presents a selected event, event switching, guest/QR/check-in/report management, offline scanner, users, and settings. [Dashboard](../resources/views/admin/dashboard.blade.php) has admission statistics and recent activity; it has no persisted setup progress or next-step engine. The customer home is separate from this admin layout and reads no events/guests/files; full workspace/event navigation remains planned.

Existing event and guest management form useful operational foundations. Their presence does not provide customer ownership, membership policies, customer registration, invitation design, customer messaging, or billing. The user-management form can select admin/scanner roles and must not be exposed unchanged to workspace customers. Source: [user form](../resources/views/admin/users/_form.blade.php).

## TARGET

Platform staff operate Kadify; customers operate their own events. The customer shell always shows the current workspace/event and permitted actions. Proposed navigation: Overview, Events, Guests, Invitation Cards, QR Passes, Scanner Team, Messages, Check-ins, Reports, Settings. Group or defer infrequent destinations so navigation remains understandable on phones.

Overview should answer “What should I do next?” with event identity, a meaningful setup state, one next action, and a few useful counts. For example, “Continue setup” may lead to scanner-team setup after valid event, guest, invitation, and QR steps. Percentages must be calculated from a defined checklist; do not show fictional progress or treat a background generation job as complete when queued.

The 2026-10-25 target prioritizes the [reference invitation journey](REFERENCE_UX_FLOW.md). Start with the event name, **Continue Setup**, and four compact values: Guest List, Invitations Sent, Confirmed, Scanner Team ready. “Confirmed” counts attending guest records; it must not imply actual people admitted. A separate report may show expected permitted places with a clear label. Use one next action based on authoritative readiness:

| Observed state | Next action |
| --- | --- |
| No event | Choose Event Type |
| Event details incomplete | Continue Event Details |
| Valid details, no saved preview | Create Invitation when engine available |
| Saved card, no guests | Add or Import Guests |
| Eligible unsent recipients | Review and Send Invitations |
| Active batch | Track Sending Progress |
| Invitations shared, responses pending | View Responses; offer authorized reminders separately |
| Event approaching, scanner setup incomplete | Prepare Scanner Team |

Show Invited, Attending, Not Attending, Awaiting Response, Messages Sent and Messages Failed in the response view. Keep Admitted, Remaining, Fully Used Passes and Partial Passes in the admission view. All aggregates use event/workspace-scoped records and the definitions in [RSVP/tickets](RSVP_AND_GUEST_TICKETS.md). Refresh/poll only while useful, show loading/last-updated/error states, and never invent live changes or reveal another event through polling. Keep event identity and access checks on every refresh.

Owners and authorized Managers act through workspace policies. Scanner assignment is an event permission, not a workspace-administration role. Switching UI context must not grant resource access. All counts, search, downloads, imports, generated cards, messages, and reports must be scoped server-side. See [multi-tenancy](MULTI_TENANCY.md) and [roles](ROLES_AND_PERMISSIONS.md).

## PLANNED

Phase 8 creates the portal shell after tenant isolation foundations; Phase 9 exposes authorized event self-service; Phase 10 launches registration/onboarding; Phases 11–12 add workspace team and scanner management. Show features only when implemented and permitted; keep unavailable actions clearly explained where necessary. Do not give customers platform settings or platform-wide user administration.

Use recoverable forms, concise empty states, visible saving/error feedback, and a consistent context switcher. Permission failures and archived/cancelled/offline-locked events require helpful next actions. Preserve [scanner](SCANNER_ARCHITECTURE.md) continuity while customer screens evolve.

## REQUIRES VERIFICATION

Test a new owner, returning owner, manager with limited access, removed member, multi-event customer, and scanner account. Verify wrong-workspace URLs, context switching, empty data, long event names, denied exports, and events locked for offline admission. Bilingual/responsive and browser acceptance are not yet established.

## IMPLEMENTED — P4-003 minimal read-only home

A protected session/customer-only GET /customer now renders an independent own-membership home in EN/SW. It shows only own active owner/manager workspace names/roles with pagination/logout, without querying event/guest/file data or admin layout. This is a closed identity foundation; customer event/guest management, public signup/onboarding and writers are still planned. [Source contract and checks](CUSTOMER_WEB_IDENTITY.md).

## IMPLEMENTED — P4-009 customer workspace setup (2026-10-23)

Existing authenticated customers now create an empty workspace through two protected HTML routes and automatically become its active owner. Exact customer role is rechecked under the creator lock before creation/replay; submitted ownership/global fields grant no authority. Matching retries add no rows and failed inserts roll back. Full isolated source gate: **1,275 tests / 22,874 assertions**. Event/guest writers, signup and complete tenant isolation remain closed; no configured data or deployment changed. [Setup contract and verification limits](CUSTOMER_WORKSPACE_SETUP.md).
